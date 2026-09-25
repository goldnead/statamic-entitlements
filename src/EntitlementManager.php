<?php

namespace Goldnead\Entitlements;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Goldnead\Entitlements\Contracts\PackageResolver;
use Goldnead\Entitlements\Contracts\SubjectResolver;
use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Events\EntitlementGranted;
use Goldnead\Entitlements\Events\EntitlementPending;
use Goldnead\Entitlements\Events\EntitlementRenewed;
use Goldnead\Entitlements\Events\EntitlementRevoked;
use Goldnead\Entitlements\Events\LimitReached;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Limits\LimitCatalog;
use Goldnead\Entitlements\Limits\Quota;
use Goldnead\Entitlements\Limits\QuotaManager;
use Goldnead\Entitlements\Mail\SendLimitReachedMail;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\AccessDecision;
use Goldnead\Entitlements\Support\StateResolver;
use Goldnead\Entitlements\Support\SubjectExtensions;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\IdentityContracts\Identity;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * The public API. Everything a consumer does to entitlements goes through here
 * or through the {@see Entitlements} facade.
 *
 * ## What this class does not do
 *
 * It does not create accounts, send mail, issue magic links or talk to a payment
 * provider. In the system this was extracted from, one method — `provision()` —
 * did all four alongside writing the grant, which meant a failing mail server
 * could take down a paid checkout, and a grant could not be written at all
 * without a mailer configured. Those three concerns stayed behind; what crosses
 * into the consumer is an event.
 *
 * ## Idempotency
 *
 * Every write is safe to repeat. Not because the code checks first — it does
 * that too — but because the database refuses the second row: the unique index
 * over (subject, product, source, source_ref, brand) is part of the table's
 * first migration. `firstOrCreate()` in PHP is a SELECT followed by an INSERT,
 * and the gap between them is precisely the race that two webhook deliveries or
 * a double-clicked checkout button win. Here the INSERT is attempted and the
 * violation is caught, so the loser of the race re-reads the winner's row
 * instead of writing a duplicate.
 *
 * ## Transitions fire once
 *
 * `claimPending()` and `revoke()` use a conditional UPDATE and check the
 * affected-row count. Two simultaneous confirmations of the same opt-in mean one
 * UPDATE reports 1 and the other reports 0; only the winner fires the event and
 * only the winner's caller delivers anything. This is the mechanism the source
 * system used for exactly this purpose and the one part of it that needed no
 * correction.
 */
class EntitlementManager
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly SubjectResolver $subjects,
        private readonly PackageResolver $packages,
    ) {}

    // ---------------------------------------------------------------- writing

    /**
     * Grant access, idempotently.
     *
     * Returns the existing grant untouched when one already exists for the
     * tuple. Three consequences worth stating out loud:
     *
     *  - A repeated call never widens an existing window. If a grant needs a new
     *    expiry, that is an explicit change, not a side effect of a retry.
     *  - A grant that is currently Pending is claimed to Active, because that is
     *    a real transition somebody asked for. It fires EntitlementGranted.
     *  - **A revoked grant stays revoked.** It is returned as it is and nothing
     *    fires. A retried webhook must not undo a refund, and this is the only
     *    place that could silently do so. Restoring access after a revocation is
     *    a decision, so it has its own method: {@see self::restore()}.
     *
     * A `startsAt` in the future produces a {@see EntitlementState::Scheduled}
     * grant and fires nothing — an announcement is not an event. The scheduled
     * pass fires EntitlementGranted when the clock reaches it.
     *
     * @param  array<string, mixed>  $meta
     */
    public function grant(
        mixed $subject,
        string $productSlug,
        string $source,
        ?string $sourceRef = null,
        ?DateTimeInterface $startsAt = null,
        ?DateTimeInterface $expiresAt = null,
        ?DateTimeInterface $graceUntil = null,
        array $meta = [],
        ?Identity $actor = null,
    ): Entitlement {
        return $this->write(
            subject: $subject,
            productSlug: $productSlug,
            source: $source,
            sourceRef: $sourceRef,
            status: EntitlementState::Active,
            startsAt: $startsAt ?? CarbonImmutable::now('UTC'),
            expiresAt: $expiresAt,
            graceUntil: $graceUntil,
            meta: $meta,
            actor: $actor,
        );
    }

    /**
     * Park a grant without access, waiting for a confirmation.
     *
     * The confirm-first lead-magnet path. Never downgrades: a subject who
     * already has access keeps it if they re-submit the form while a
     * confirmation is still open, which is what the source system got right and
     * what a naive `updateOrCreate` would get wrong.
     *
     * @param  array<string, mixed>  $meta
     */
    public function grantPending(
        mixed $subject,
        string $productSlug,
        string $source,
        ?string $sourceRef = null,
        ?DateTimeInterface $expiresAt = null,
        array $meta = [],
        ?Identity $actor = null,
    ): Entitlement {
        return $this->write(
            subject: $subject,
            productSlug: $productSlug,
            source: $source,
            sourceRef: $sourceRef,
            status: EntitlementState::Pending,
            startsAt: null,
            expiresAt: $expiresAt,
            graceUntil: null,
            meta: $meta,
            actor: $actor,
        );
    }

    /**
     * Flip a Pending grant to Active, atomically. Returns whether this call won.
     *
     * The conditional `WHERE status = pending` is the concurrency guard: the
     * database serialises two racing confirmations, exactly one UPDATE affects a
     * row, and only that caller gets `true`. Everything the consumer does once —
     * send the download, issue the link — hangs off that boolean.
     *
     * A losing caller gets `false` and must stay silent. That is the difference
     * between "delivered once" and "delivered twice because the queue retried".
     */
    public function claimPending(Entitlement $entitlement, ?Identity $actor = null): bool
    {
        $won = $this->newQuery()
            ->whereKey($entitlement->getKey())
            ->where('status', EntitlementState::Pending->value)
            ->update([
                'status' => EntitlementState::Active->value,
                'starts_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
                'revoked_at' => null,
                'revoked_reason' => null,
                'announced_state' => EntitlementState::Active->value,
                'updated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
            ]) === 1;

        if (! $won) {
            return false;
        }

        $entitlement->refresh();

        $this->events->dispatch(new EntitlementGranted($entitlement, EntitlementState::Pending, $actor));

        return true;
    }

    /**
     * Push an existing grant's window forward.
     *
     * A subscription that renews every month must not create a grant every
     * month. `grant()` deliberately refuses to widen an existing window — "a
     * retry is not a renewal" — so a billing cycle calling it either does
     * nothing (same `source_ref`) or writes a second row (new `source_ref`).
     * Twelve months of that is twelve grants for one membership, and the
     * question "does this person have access" becomes an aggregation.
     *
     * So renewal is its own verb, and it is deliberately narrow:
     *
     * - **It never shortens.** A late webhook carrying last month's date must
     *   not take away time somebody paid for. If `$until` is earlier than what
     *   the row already holds, the row wins and nothing is announced.
     * - **It lifts a grace period.** Payment arriving is exactly the thing a
     *   grace period was waiting for.
     * - **It refuses a revoked grant.** Access taken away deliberately is not
     *   restored by a payment; that is what `restore()` is for, and it is a
     *   decision somebody makes.
     * - **It returns null when there is nothing to renew**, so the caller can
     *   fall back to `grant()` — the honest answer for a first payment.
     *
     * @param  mixed  $subject  the subject reference, as everywhere else
     */
    public function renew(
        mixed $subject,
        string $productSlug,
        DateTimeInterface $until,
        ?Identity $actor = null,
    ): ?Entitlement {
        $entitlement = $this->forSubject($subject)
            ->where('product_slug', $productSlug)
            ->whereNot('status', EntitlementState::Revoked->value)
            ->orderByDesc('expires_at')
            ->first();

        if ($entitlement === null) {
            return null;
        }

        $bisher = $entitlement->expires_at;
        $neu = CarbonImmutable::parse($until)->utc();

        // Nie verkuerzen. Eine verspaetete Zustellung traegt ein altes Datum,
        // und die Zeit ist bezahlt.
        if ($bisher !== null && $neu->lessThanOrEqualTo($bisher)) {
            return $entitlement;
        }

        $entitlement->forceFill([
            'expires_at' => $neu,
            'grace_until' => null,
            'status' => EntitlementState::Active->value,
            // Der Zustand ist wieder Active, also darf der naechste Ablauf
            // erneut angekuendigt werden.
            'announced_state' => EntitlementState::Active->value,
        ])->save();

        $this->events->dispatch(new EntitlementRenewed($entitlement->fresh() ?? $entitlement, $bisher, $actor));

        return $entitlement->fresh() ?? $entitlement;
    }

    /**
     * Take access away, with a reason. Returns whether this call performed it.
     *
     * The source system had a `revoked` status nobody ever wrote and a
     * `revoked_at` column nobody ever read — production held forty-eight grants
     * and zero revocations, not because none were needed but because there was
     * no way to record one. This method is the missing half.
     *
     * Both columns are written together and the resolver answers on either, so a
     * write interrupted halfway still reads as revoked. Failing closed is the
     * only safe direction for a revocation.
     *
     * The reason is required. An empty one is rejected rather than stored,
     * because "revoked, reason: (blank)" six months later gets overturned by
     * whoever is on support that day.
     */
    public function revoke(Entitlement $entitlement, string $reason, ?Identity $actor = null): bool
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A revocation needs a reason.');
        }

        $previous = $entitlement->state();

        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');

        $won = $this->newQuery()
            ->whereKey($entitlement->getKey())
            ->whereNull('revoked_at')
            ->where('status', '!=', EntitlementState::Revoked->value)
            ->update([
                'status' => EntitlementState::Revoked->value,
                'revoked_at' => $now,
                'revoked_reason' => mb_substr($reason, 0, 255),
                'announced_state' => EntitlementState::Revoked->value,
                'updated_at' => $now,
            ]) === 1;

        if (! $won) {
            return false;
        }

        $entitlement->refresh();

        $this->events->dispatch(new EntitlementRevoked($entitlement, $reason, $previous, $actor));

        return true;
    }

    /**
     * Undo a revocation deliberately.
     *
     * Separate from `grant()` so that resurrecting access is always something
     * somebody chose, never something a retried job did. Fires
     * EntitlementGranted when the restored grant is actually Active — a restored
     * grant whose window has meanwhile closed is Expired, and announcing that as
     * a grant would be a lie.
     */
    public function restore(Entitlement $entitlement, ?Identity $actor = null): bool
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');

        $won = $this->newQuery()
            ->whereKey($entitlement->getKey())
            ->where(fn (Builder $q) => $q
                ->whereNotNull('revoked_at')
                ->orWhere('status', EntitlementState::Revoked->value)
            )
            ->update([
                'status' => EntitlementState::Active->value,
                'revoked_at' => null,
                'revoked_reason' => null,
                'announced_state' => null,
                'updated_at' => $now,
            ]) === 1;

        if (! $won) {
            return false;
        }

        $entitlement->refresh();

        if ($entitlement->state() === EntitlementState::Active) {
            $entitlement->forceFill(['announced_state' => EntitlementState::Active->value])->saveQuietly();

            $this->events->dispatch(new EntitlementGranted($entitlement, EntitlementState::Revoked, $actor));
        }

        return true;
    }

    /**
     * Put a grant into a grace period that outlives its expiry.
     *
     * No event: a grace period still grants access, so nothing changed from the
     * subject's point of view, and the four events are the four the extraction
     * spec names. When the grace period runs out the grant becomes Expired and
     * the scheduled pass announces that.
     *
     * This exists because `grace_period` would otherwise be a state with no
     * writer — which is exactly the shape of the `revoked_at` defect this
     * package was built to fix.
     */
    public function enterGracePeriod(Entitlement $entitlement, DateTimeInterface $until): bool
    {
        if ($entitlement->isRevoked()) {
            return false;
        }

        $entitlement->forceFill([
            'status' => EntitlementState::GracePeriod->value,
            'grace_until' => $until,
            'announced_state' => EntitlementState::Active->value,
        ])->save();

        return true;
    }

    // ---------------------------------------------------------------- reading

    public function stateOf(Entitlement $entitlement): EntitlementState
    {
        return StateResolver::resolve($entitlement);
    }

    /**
     * Does this subject currently have access to this product?
     *
     * The semantics over several grants are an **OR**, exactly as in the source:
     * access exists as soon as any one grant is Active or in its grace period. A
     * customer who bought a product twice and had one purchase refunded still has
     * the other one, and an expired trial next to a paid licence does not lock
     * anybody out. This is load-bearing — the de-duplication migration in the
     * source system is only lossless because of it.
     *
     * Bundles are consulted through the optional {@see PackageResolver}: with
     * none bound, only the product's own slug is considered.
     */
    public function decide(mixed $subject, string $productSlug): AccessDecision
    {
        $slugs = [$productSlug, ...$this->packages->packagesContaining($productSlug)];

        // The subject's own grants first, then those of every subject it acts
        // for (see extendSubjects()). With no extension registered this is the
        // one subject and the query is the one it always was.
        $subjects = $this->subjectsOf($subject);

        $granted = StateResolver::constrainToAccess(
            $this->forSubjects($subjects)->whereIn('product_slug', array_unique($slugs))
        )->first();

        if ($granted instanceof Entitlement) {
            return AccessDecision::entitled($granted);
        }

        // No access. Hand back the closest grant that exists so a refusal can be
        // explained — "your access ran out on the 3rd" rather than "no".
        $closest = $this->forSubjects($subjects)
            ->whereIn('product_slug', array_unique($slugs))
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('expires_at')
            ->orderByDesc('id')
            ->first();

        return AccessDecision::refused($closest);
    }

    public function allows(mixed $subject, string $productSlug): bool
    {
        return $this->decide($subject, $productSlug)->allowed;
    }

    /**
     * Every product slug this subject can currently reach.
     *
     * @return list<string>
     */
    public function activeProductSlugsFor(mixed $subject): array
    {
        return StateResolver::constrainToAccess($this->forSubjects($this->subjectsOf($subject)))
            ->pluck('product_slug')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * A query scoped to one subject. The building block for consumers that need
     * something this API does not offer.
     *
     * @return Builder<Entitlement>
     */
    public function forSubject(mixed $subject): Builder
    {
        $reference = $this->reference($subject);

        return $this->newQuery()
            ->where('subject_type', $reference->type)
            ->where('subject_id', $reference->id);
    }

    /**
     * A query over the grants of several subjects at once.
     *
     * @param  iterable<mixed>  $subjects  Anything reference() accepts.
     * @return Builder<Entitlement>
     */
    public function forSubjects(iterable $subjects): Builder
    {
        $references = [];

        foreach ($subjects as $subject) {
            $references[] = $this->reference($subject);
        }

        return $this->newQuery()->where(function (Builder $query) use ($references): void {
            if ($references === []) {
                // Nobody, so nothing. Without this the empty group would match
                // every row in the table.
                $query->whereRaw('1 = 0');

                return;
            }

            foreach ($references as $reference) {
                $query->orWhere(fn (Builder $q) => $q
                    ->where('subject_type', $reference->type)
                    ->where('subject_id', $reference->id));
            }
        });
    }

    /**
     * Let the grants of further subjects count for a subject.
     *
     * The seam for teams, organisations, households: the resolver receives the
     * subject exactly as a caller passed it plus its reference, and returns the
     * subjects whose grants also apply — models, SubjectReferences, anything the
     * bound SubjectResolver accepts. Applied one level deep, never recursively.
     * See {@see SubjectExtensions}.
     *
     * @param  callable(mixed, SubjectReference): iterable<mixed>  $resolver
     */
    public function extendSubjects(callable $resolver): void
    {
        app(SubjectExtensions::class)->register($resolver);
    }

    /**
     * The subject itself, then every subject it acts for, de-duplicated.
     *
     * The order is the tie-breaker for limits: at equal height the subject's
     * own grant wins over a team's.
     *
     * @return list<SubjectReference>
     */
    public function subjectsOf(mixed $subject): array
    {
        $own = $this->reference($subject);
        $all = [$own->key() => $own];

        $extensions = app(SubjectExtensions::class);

        if ($extensions->isEmpty()) {
            return [$own];
        }

        foreach ($extensions->extraFor($subject, $own) as $extra) {
            try {
                $reference = $this->reference($extra);
            } catch (InvalidArgumentException) {
                continue;
            }

            $all[$reference->key()] ??= $reference;
        }

        return array_values($all);
    }

    // ----------------------------------------------------------------- limits

    /**
     * The number this subject may have or use of `$key`: null is unlimited,
     * 0 is none. Highest value across every grant that currently gives access,
     * the subject's own and those of the subjects it acts for.
     */
    public function limit(mixed $subject, string $key): ?int
    {
        return $this->quotas()->quota($subject, $key)->limit;
    }

    /** Everything about one limit: height, holder, period, used, remaining. */
    public function quota(mixed $subject, string $key, ?int $current = null): Quota
    {
        return $this->quotas()->quota($subject, $key, $current);
    }

    /**
     * Every limit this subject has, keyed by limit key.
     *
     * @return array<string, Quota>
     */
    public function quotasFor(mixed $subject): array
    {
        return $this->quotas()->quotasFor($subject);
    }

    /**
     * What is left: null when unlimited. For a stock limit pass what the caller
     * counted (`$current`); for a usage limit the counter here is used.
     */
    public function remaining(mixed $subject, string $key, ?int $current = null): ?int
    {
        return $this->quotas()->quota($subject, $key, $current)->remaining();
    }

    /** Book `$amount` against a usage limit, atomically. False means refused. */
    public function consume(mixed $subject, string $key, int $amount = 1): bool
    {
        return $this->quotas()->consume($subject, $key, $amount);
    }

    /** Give back what a failed job booked. False when there was nothing to give back. */
    public function release(mixed $subject, string $key, int $amount = 1): bool
    {
        return $this->quotas()->release($subject, $key, $amount);
    }

    /**
     * Whether a stock limit has room for `$adding` more, given what the caller
     * counted. Records the count, and fires LimitReached once when it is full.
     */
    public function withinLimit(mixed $subject, string $key, int $current, int $adding = 1): bool
    {
        return $this->quotas()->withinLimit($subject, $key, $current, $adding);
    }

    /** Set the counter of the current period back to zero. */
    public function resetUsage(mixed $subject, string $key, ?Identity $actor = null): bool
    {
        return $this->quotas()->reset($subject, $key, $actor);
    }

    /**
     * The limits a product carries, config and stored rows merged.
     *
     * @return array<string, array{value: int|null, period: string|null}>
     */
    public function limitsFor(string $productSlug): array
    {
        return app(LimitCatalog::class)->forProduct($productSlug);
    }

    /**
     * Replace the stored limits of a product.
     *
     * @param  array<string, int|null|array{value?: int|null, period?: string|null}>  $limits
     */
    public function setLimits(string $productSlug, array $limits): void
    {
        app(LimitCatalog::class)->store($productSlug, $limits);
    }

    /**
     * Who gets the "limit reached" mail. Default: the person who reached it.
     * Return addresses, or `['email' => …, 'name' => …]` pairs; null restores
     * the default.
     *
     * @param  (callable(LimitReached): iterable<string|array{email: string, name?: string|null}>)|null  $resolver
     */
    public function mailRecipientsUsing(?callable $resolver): void
    {
        SendLimitReachedMail::recipientsUsing($resolver);
    }

    private function quotas(): QuotaManager
    {
        return app(QuotaManager::class);
    }

    /** @return Builder<Entitlement> */
    public function query(): Builder
    {
        return $this->newQuery();
    }

    public function reference(mixed $subject): SubjectReference
    {
        return $this->subjects->reference($subject);
    }

    public function subjectLabel(SubjectReference $reference): string
    {
        return $this->subjects->label($reference) ?: $reference->key();
    }

    // --------------------------------------------------------------- internals

    /**
     * The one write path, shared by grant() and grantPending().
     *
     * @param  array<string, mixed>  $meta
     */
    private function write(
        mixed $subject,
        string $productSlug,
        string $source,
        ?string $sourceRef,
        EntitlementState $status,
        ?DateTimeInterface $startsAt,
        ?DateTimeInterface $expiresAt,
        ?DateTimeInterface $graceUntil,
        array $meta,
        ?Identity $actor,
    ): Entitlement {
        $reference = $this->reference($subject);

        $productSlug = $this->required($productSlug, 'product slug');
        $source = $this->required($source, 'source');

        // Absence of an external reference is the empty string, never NULL:
        // NULLs do not collide in a unique index, so a nullable column here would
        // switch the idempotency guarantee off for every manual grant.
        $sourceRef = (string) ($sourceRef ?? '');

        $key = [
            'subject_type' => $reference->type,
            'subject_id' => $reference->id,
            'product_slug' => $productSlug,
            'source' => $source,
            'source_ref' => $sourceRef,
        ];

        if ($existing = $this->newQuery()->where($key)->first()) {
            return $this->reconcile($existing, $status, $actor);
        }

        $entitlement = new Entitlement;
        $entitlement->forceFill($key + [
            'status' => $status->value,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'grace_until' => $graceUntil,
            'meta' => $meta ?: null,
        ]);

        try {
            $entitlement->save();
        } catch (UniqueConstraintViolationException) {
            // Lost the race. The winner's row is the truth; this call becomes a
            // read. Without the database constraint there would be no exception
            // here and both callers would have written a row.
            $winner = $this->newQuery()->where($key)->firstOrFail();

            return $this->reconcile($winner, $status, $actor);
        }

        $this->announceCreation($entitlement, $status, $actor);

        return $entitlement;
    }

    /**
     * What a second write against an existing grant is allowed to do.
     *
     * Almost nothing, deliberately. The one transition it performs is
     * Pending -> Active, because a confirmation arriving is a real thing that
     * happened. Everything else returns the row as it stands.
     */
    private function reconcile(Entitlement $existing, EntitlementState $wanted, ?Identity $actor): Entitlement
    {
        if ($wanted === EntitlementState::Active && $existing->state() === EntitlementState::Pending) {
            $this->claimPending($existing, $actor);
        }

        return $existing;
    }

    /**
     * Fires the event for a freshly written grant, and records that it was fired.
     *
     * Scheduled gets no event and no marker: the scheduled pass will announce it
     * when the clock arrives. A grant created already expired — a backfill or an
     * import of historical purchases — gets the marker but no event, because
     * announcing the expiry of something that was never active is noise.
     */
    private function announceCreation(Entitlement $entitlement, EntitlementState $wanted, ?Identity $actor): void
    {
        $state = $entitlement->state();

        if ($state === EntitlementState::Scheduled) {
            return;
        }

        $entitlement->forceFill(['announced_state' => $state->value])->saveQuietly();

        if ($state === EntitlementState::Active) {
            $this->events->dispatch(new EntitlementGranted($entitlement, null, $actor));

            return;
        }

        if ($state === EntitlementState::Pending && $wanted === EntitlementState::Pending) {
            $this->events->dispatch(new EntitlementPending($entitlement, null, $actor));
        }
    }

    private function required(string $value, string $what): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('An entitlement needs a '.$what.'.');
        }

        return $value;
    }

    /** @return Builder<Entitlement> */
    private function newQuery(): Builder
    {
        return Entitlement::query();
    }
}

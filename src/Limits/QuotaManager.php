<?php

namespace Goldnead\Entitlements\Limits;

use Carbon\CarbonImmutable;
use Goldnead\Entitlements\EntitlementManager;
use Goldnead\Entitlements\Events\LimitReached;
use Goldnead\Entitlements\Events\UsageConsumed;
use Goldnead\Entitlements\Events\UsageReset;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Models\Usage;
use Goldnead\Entitlements\Models\UsageReceiptRecord;
use Goldnead\Entitlements\Support\StateResolver;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\IdentityContracts\Identity;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Limits and their counters.
 *
 * ## Which number applies
 *
 * Every grant that currently gives access is considered — the subject's own and
 * those of every subject it acts for ({@see EntitlementManager::extendSubjects()}).
 * Each grant's product may carry a value for the key; the **highest** wins,
 * unlimited above any number. At equal height the subject's own grant wins over
 * a team's, so a person who bought their own plan counts against their own
 * counter. With no grant at all the fallback product applies (a free plan for
 * everybody), and without one the limit is 0.
 *
 * An expired or revoked grant is not considered, so when the grant that set the
 * highest value ends, the limit falls to the next grant's, or to the fallback,
 * or to zero. Nothing needs to be recomputed for that: the answer is derived on
 * every read.
 *
 * ## Where usage is counted
 *
 * At the **holder**: the subject whose grant the limit comes from. A team
 * member using the team's plan books against the team's counter, which is the
 * only way "50 analyses per year for the choir" means 50 and not 50 per singer.
 *
 * ## Why the counter cannot be overbooked
 *
 * The booking is one conditional UPDATE: `used = used + n WHERE used <= limit - n`.
 * The engine serialises two of them on the same row and the second sees the
 * first one's result, so of two bookings on the last slot exactly one affects a
 * row. There is no read-then-write anywhere on this path and no `lockForUpdate()`,
 * which on SQLite compiles to nothing at all.
 *
 * The row itself is created with `insertOrIgnore` against a unique index, so two
 * first bookings in a new period cannot create two counters either. Not a
 * try/catch around an INSERT: on Postgres a failed INSERT poisons the enclosing
 * transaction, and a caller booking inside its own transaction would find every
 * later statement refused.
 */
class QuotaManager
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly LimitCatalog $catalog,
    ) {}

    public function quota(mixed $subject, string $key, ?int $current = null): Quota
    {
        $quota = $this->resolve($subject, $key);

        if ($quota->kind === Quota::KIND_STOCK) {
            // Without a count from the caller, the last one withinLimit() was
            // told, or nothing: this addon does not count a stock itself.
            return $quota->withUsed($current ?? ($this->catalog->ready() ? $this->findCounter($quota)?->used : null));
        }

        return $quota->withUsed($this->readUsed($quota));
    }

    /**
     * Every limit the subject has, keyed by limit key: every declared key, plus
     * every key a product the subject can reach carries.
     *
     * @return array<string, Quota>
     */
    public function quotasFor(mixed $subject): array
    {
        $keys = array_keys($this->catalog->keys());

        $products = StateResolver::constrainToAccess(
            $this->entitlements()->forSubjects($this->entitlements()->subjectsOf($subject))
        )->pluck('product_slug')->unique()->values()->all();

        foreach ($this->catalog->forProducts($products) as $limits) {
            $keys = [...$keys, ...array_keys($limits)];
        }

        $fallback = $this->fallbackProduct($this->entitlements()->subjectsOf($subject)[0]);

        if ($fallback !== null) {
            $keys = [...$keys, ...array_keys($this->catalog->forProduct($fallback))];
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        $quotas = [];

        foreach ($keys as $key) {
            $quotas[$key] = $this->quota($subject, $key);
        }

        return $quotas;
    }

    /**
     * Book against a usage limit. The receipt on success, null when refused —
     * so `if (! consume(...))` reads as it always did.
     */
    public function consume(mixed $subject, string $key, int $amount = 1): ?UsageReceipt
    {
        if ($amount < 1) {
            throw new InvalidArgumentException('Consume at least 1.');
        }

        if (! $this->ready()) {
            return null;
        }

        $quota = $this->resolve($subject, $key);

        if ($quota->source === Quota::SOURCE_NONE || $quota->holder === null) {
            return null;
        }

        if ($quota->kind === Quota::KIND_STOCK) {
            throw new LogicException(
                'The limit ['.$key.'] is a stock limit the caller counts. Ask withinLimit() with the current count instead of consume().'
            );
        }

        if ($quota->limit !== null && $amount > $quota->limit) {
            return null;
        }

        $row = $this->counter($quota);

        $booking = Usage::query()->whereKey($row->getKey());

        if ($quota->limit !== null) {
            $booking->where('used', '<=', $quota->limit - $amount);
        }

        $receiptId = (string) Str::uuid();

        // The booking and the server's copy of its receipt together, or
        // neither: a receipt without its booking would give back what was
        // never taken, a booking without its receipt could never be given back.
        $won = DB::transaction(function () use ($booking, $amount, $quota, $row, $receiptId, $key): bool {
            $won = $booking->update([
                'used' => DB::raw('used + '.$amount),
                'limit_value' => $quota->limit,
            ]) === 1;

            if (! $won || $quota->holder === null) {
                return false;
            }

            UsageReceiptRecord::query()->insert([
                'id' => $receiptId,
                'brand_id' => (int) $row->brand_id,
                'usage_id' => $row->getKey(),
                'subject_type' => $quota->holder->type,
                'subject_id' => $quota->holder->id,
                'limit_key' => $key,
                'period_key' => $row->period_key,
                'amount' => $amount,
                'released' => 0,
                'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
            ]);

            return true;
        });

        if (! $won) {
            return null;
        }

        $used = (int) Usage::query()->whereKey($row->getKey())->value('used');
        $now = CarbonImmutable::now('UTC');
        $own = $this->entitlements()->reference($subject);

        $this->events->dispatch(new UsageConsumed(
            subject: $own,
            holder: $quota->holder,
            key: $key,
            amount: $amount,
            used: $used,
            limit: $quota->limit,
            product: $quota->product,
            period: $quota->period,
            periodStart: $quota->periodStart,
            periodEnd: $quota->periodEnd,
            occurredAt: $now,
            brandId: (int) $row->brand_id,
        ));

        if ($quota->limit !== null && $used >= $quota->limit) {
            $this->announceReached($row, $quota, $own, $used, $now);
        }

        return new UsageReceipt(
            id: $receiptId,
            holderType: $quota->holder->type,
            holderId: $quota->holder->id,
            key: $key,
            periodKey: $row->period_key,
            amount: $amount,
            brandId: (int) $row->brand_id,
        );
    }

    /**
     * Give back what a booking took.
     *
     * With the booking's receipt, into exactly the counter it went to — the
     * period it was booked in, at the holder that held it — and once only.
     * `$subject` and `$key` are not consulted then; the receipt decides.
     *
     * Without one, only into the current period of the current holder, and
     * only as much as that period holds: a booking from last month cannot be
     * found without its receipt, so rather than lowering the wrong month this
     * does nothing and logs a warning.
     *
     * @param  UsageReceipt|array<string, mixed>|null  $receipt
     */
    public function release(mixed $subject, string $key, ?int $amount = null, UsageReceipt|array|null $receipt = null): bool
    {
        if ($amount !== null && $amount < 1) {
            throw new InvalidArgumentException('Release at least 1.');
        }

        if (! $this->ready()) {
            return false;
        }

        if ($receipt !== null) {
            return $this->releaseReceipt($subject, $key, UsageReceipt::idOf($receipt), $amount);
        }

        $amount ??= 1;
        $quota = $this->resolve($subject, $key);

        if ($quota->kind === Quota::KIND_STOCK || $quota->holder === null) {
            return false;
        }

        $row = $this->findCounter($quota);

        $won = $row !== null && Usage::query()
            ->whereKey($row->getKey())
            ->where('used', '>=', $amount)
            ->update(['used' => DB::raw('used - '.$amount)]) === 1;

        if (! $won) {
            Log::warning('statamic-entitlements: a release without a receipt found nothing to give back in the current period; nothing was changed. Pass the receipt consume() returned.', [
                'holder' => $quota->holder->key(),
                'key' => $key,
                'amount' => $amount,
            ]);

            return false;
        }

        $this->clearReached($row, $quota->limit);

        return true;
    }

    /**
     * Give back through the server's copy of the receipt, never through what
     * the caller presents: only the id is taken from it.
     *
     * The stored row must be in the current brand (the brand scope, no
     * `forBrand()`), for the key asked about, and held by the subject or one it
     * acts for. The amount is at least 1 and at most what the receipt has left.
     * Claim and deduction run in one transaction, and the deduction is one
     * UPDATE that cannot go below zero.
     */
    private function releaseReceipt(mixed $subject, string $key, string $receiptId, ?int $amount): bool
    {
        $stored = UsageReceiptRecord::query()->whereKey($receiptId)->first();

        $refuse = function (string $why) use ($receiptId, $key): bool {
            Log::warning('statamic-entitlements: a usage receipt was refused: '.$why, [
                'receipt' => $receiptId,
                'key' => $key,
            ]);

            return false;
        };

        if ($stored === null) {
            return $refuse('unknown in this brand');
        }

        if ($stored->limit_key !== $key) {
            return $refuse('issued for another limit');
        }

        $holders = array_map(fn (SubjectReference $r) => $r->key(), $this->entitlements()->subjectsOf($subject));

        if (! in_array($stored->holder()->key(), $holders, true)) {
            return $refuse('held by a subject this one does not act for');
        }

        $left = $stored->amount - $stored->released;
        $amount ??= $left;

        if ($amount < 1 || $amount > $left) {
            return false;
        }

        return DB::transaction(function () use ($stored, $amount): bool {
            // The claim. Conditional, so two retries of one cleanup cannot
            // together give back more than was booked.
            $claimed = UsageReceiptRecord::query()
                ->whereKey($stored->getKey())
                ->whereRaw('released + ? <= amount', [$amount])
                ->update([
                    'released' => DB::raw('released + '.$amount),
                    'released_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
                ]) === 1;

            if (! $claimed) {
                return false;
            }

            // One statement, never below zero: a counter reset by hand in
            // between already gave everything back. CASE rather than
            // GREATEST, which SQLite does not have.
            Usage::query()->whereKey($stored->usage_id)->update([
                'used' => DB::raw('CASE WHEN used >= '.$amount.' THEN used - '.$amount.' ELSE 0 END'),
            ]);

            Usage::query()
                ->whereKey($stored->usage_id)
                ->whereNotNull('reached_at')
                ->where(fn ($q) => $q->whereNull('limit_value')->orWhereColumn('used', '<', 'limit_value'))
                ->update(['reached_at' => null]);

            return true;
        });
    }

    public function withinLimit(mixed $subject, string $key, int $current, int $adding = 1): bool
    {
        if ($current < 0 || $adding < 0) {
            throw new InvalidArgumentException('Counts cannot be negative.');
        }

        $quota = $this->resolve($subject, $key);

        if ($quota->source === Quota::SOURCE_NONE || $quota->holder === null) {
            return false;
        }

        if ($quota->kind === Quota::KIND_USAGE) {
            // A usage limit keeps its own counter; the caller's count is not
            // what decides it.
            $remaining = $quota->withUsed($this->readUsed($quota))->remaining();

            return $remaining === null || $remaining >= $adding;
        }

        $fits = $quota->limit === null || $current + $adding <= $quota->limit;

        // Recorded only when the caller counted for the holder itself. A
        // member asking with their own count (their projects, not the
        // team's) would overwrite the team's figure with a smaller one, and
        // with two teams it would be anybody's guess which team it lands on.
        // In a team context, pass the team.
        $own = $this->entitlements()->subjectsOf($subject)[0];

        if (! $this->ready() || ! $quota->holder->equals($own)) {
            return $fits;
        }

        // The count is recorded, so the Control Panel can show a stock limit's
        // use too, and so "full" can be announced once rather than on every
        // refused attempt.
        $row = $this->counter($quota);

        Usage::query()->whereKey($row->getKey())->update([
            'used' => $current,
            'limit_value' => $quota->limit,
        ]);

        if ($quota->limit === null) {
            return $fits;
        }

        // The addition that fills the last slot is the moment it is reached.
        $after = $fits ? $current + $adding : $current;

        if ($after >= $quota->limit) {
            $this->announceReached($row, $quota, $this->entitlements()->reference($subject), $after, CarbonImmutable::now('UTC'));
        } else {
            $this->clearReached($row, $quota->limit);
        }

        return $fits;
    }

    public function reset(mixed $subject, string $key, ?Identity $actor = null): bool
    {
        if (! $this->ready()) {
            return false;
        }

        $quota = $this->resolve($subject, $key);

        if ($quota->kind === Quota::KIND_STOCK || $quota->holder === null) {
            return false;
        }

        $row = $this->findCounter($quota);

        if ($row === null || $row->used === 0) {
            return false;
        }

        $previous = $row->used;

        $won = Usage::query()
            ->whereKey($row->getKey())
            ->where('used', '>', 0)
            ->update(['used' => 0, 'reached_at' => null]) === 1;

        if (! $won) {
            return false;
        }

        $this->events->dispatch(new UsageReset(
            holder: $quota->holder,
            key: $key,
            previous: $previous,
            reason: UsageReset::REASON_MANUAL,
            period: $quota->period,
            periodStart: $quota->periodStart,
            periodEnd: $quota->periodEnd,
            actor: $actor,
            occurredAt: CarbonImmutable::now('UTC'),
            brandId: (int) $row->brand_id,
        ));

        return true;
    }

    /**
     * Which limit applies, without its counter.
     */
    public function resolve(mixed $subject, string $key, ?CarbonImmutable $now = null): Quota
    {
        $now ??= CarbonImmutable::now('UTC');
        $subjects = $this->entitlements()->subjectsOf($subject);
        $rank = array_flip(array_map(fn (SubjectReference $r) => $r->key(), $subjects));

        /** @var list<Entitlement> $grants */
        $grants = StateResolver::constrainToAccess($this->entitlements()->forSubjects($subjects), $now)
            ->orderBy('id')
            ->get()
            ->all();

        $limits = $this->catalog->forProducts(array_map(fn (Entitlement $g) => $g->product_slug, $grants));

        $best = null;

        foreach ($grants as $grant) {
            $definition = $limits[$grant->product_slug][$key] ?? null;

            if ($definition === null) {
                continue;
            }

            $candidate = [
                'value' => $definition['value'],
                'period' => $definition['period'],
                'grant' => $grant,
                // 0 for the subject's own grant, 1 for every subject it acts
                // for: expanders may return teams in any order, so their
                // order must not decide anything.
                'rank' => ($rank[$grant->subjectKey()] ?? PHP_INT_MAX) === 0 ? 0 : 1,
                'holder' => $grant->subjectKey(),
            ];

            if ($best === null || $this->beats($candidate, $best)) {
                $best = $candidate;
            }
        }

        if ($best !== null) {
            /** @var Entitlement $grant */
            $grant = $best['grant'];
            $anchor = $grant->starts_at ?? CarbonImmutable::parse($grant->getAttribute('created_at'))->utc();
            [$start, $end] = $this->window($best['period'], $anchor, $now, $this->catalog->anchor($key));

            return new Quota(
                key: $key,
                limit: $best['value'],
                kind: $best['period'] === null ? Quota::KIND_STOCK : Quota::KIND_USAGE,
                period: $best['period'],
                holder: new SubjectReference($grant->subject_type, $grant->subject_id),
                source: Quota::SOURCE_GRANT,
                product: $grant->product_slug,
                entitlement: $grant,
                periodStart: $start,
                periodEnd: $end,
            );
        }

        $fallback = $this->fallbackProduct($subjects[0]);
        $definition = $fallback !== null ? ($this->catalog->forProduct($fallback)[$key] ?? null) : null;

        if ($definition !== null) {
            [$start, $end] = $this->window($definition['period'], null, $now);

            return new Quota(
                key: $key,
                limit: $definition['value'],
                kind: $definition['period'] === null ? Quota::KIND_STOCK : Quota::KIND_USAGE,
                period: $definition['period'],
                holder: $subjects[0],
                source: Quota::SOURCE_FALLBACK,
                product: $fallback,
                periodStart: $start,
                periodEnd: $end,
            );
        }

        $period = $this->catalog->keys()[$key]['period'] ?? null;
        [$start, $end] = $this->window($period, null, $now);

        return new Quota(
            key: $key,
            limit: 0,
            kind: $period === null ? Quota::KIND_STOCK : Quota::KIND_USAGE,
            period: $period,
            holder: $subjects[0],
            source: Quota::SOURCE_NONE,
            periodStart: $start,
            periodEnd: $end,
        );
    }

    /**
     * The period `$now` falls into.
     *
     * Anchored on the start of the grant that sets the limit, so a yearly plan
     * bought on 14 March resets on 14 March — "with the term", as the plan was
     * sold. Without a grant (the fallback product), or with the anchor
     * `calendar` (per key in `limits.keys.<key>.anchor`, else
     * `limits.period_anchor`), calendar months and years in the application
     * timezone.
     *
     * The trade-off, decided 25.09.2026: with `grant`, a plan change starts a
     * new period, because the new grant has a new start — an upgrade in June
     * brings a fresh counter. That is how a term-based plan is sold. Where the
     * allowance belongs to the year and not to the plan (ChoirLive's analyses),
     * set the key to `calendar`: the counter is kept per holder and period,
     * not per product, so it carries over an upgrade and only the limit rises.
     *
     * Months are added without overflow and always from the anchor, never from
     * the previous period: 31 January + 1 month is 28 February, and the period
     * after that starts on 31 March again rather than drifting to the 28th.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public function window(?string $period, ?CarbonImmutable $anchor, CarbonImmutable $now, ?string $mode = null): array
    {
        if ($period === null) {
            return [null, null];
        }

        $months = $period === 'year' ? 12 : 1;

        // Per key (`limits.keys.<key>.anchor`) before the global setting.
        $mode ??= config('entitlements.limits.period_anchor', 'grant');

        if ($anchor === null || $mode === 'calendar') {
            $local = $now->setTimezone((string) config('app.timezone', 'UTC'));
            $start = $period === 'year' ? $local->startOfYear() : $local->startOfMonth();

            return [$start->utc(), $start->addMonthsNoOverflow($months)->utc()];
        }

        $anchor = $anchor->utc();

        if ($now->lessThan($anchor)) {
            return [$anchor, $anchor->addMonthsNoOverflow($months)];
        }

        $steps = intdiv((int) floor($anchor->diffInMonths($now, true)), $months);

        // diffInMonths counts whole calendar months; around month ends the
        // non-overflowing addition can land a period start after `now`. Step
        // back until it does not.
        while ($steps > 0 && $anchor->addMonthsNoOverflow($steps * $months)->greaterThan($now)) {
            $steps--;
        }

        while ($anchor->addMonthsNoOverflow(($steps + 1) * $months)->lessThanOrEqualTo($now)) {
            $steps++;
        }

        return [
            $anchor->addMonthsNoOverflow($steps * $months),
            $anchor->addMonthsNoOverflow(($steps + 1) * $months),
        ];
    }

    public static function periodKey(?CarbonImmutable $start): string
    {
        return $start === null ? 'stock' : $start->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * The product that applies to a subject without a grant carrying the key.
     *
     * In this order: the callback from `fallbackUsing()` (its null is a
     * decision: none), then `limits.fallback_products[<subject type>]`, then
     * `limits.fallback_product` for everybody. Without a subject only the last.
     */
    public function fallbackProduct(?SubjectReference $subject = null): ?string
    {
        if ($subject !== null && $this->fallbackResolver !== null) {
            return $this->slug(($this->fallbackResolver)($subject));
        }

        if ($subject !== null) {
            $byType = (array) config('entitlements.limits.fallback_products', []);

            if (array_key_exists($subject->type, $byType)) {
                return $this->slug($byType[$subject->type]);
            }
        }

        return $this->slug(config('entitlements.limits.fallback_product'));
    }

    /** @param  (callable(SubjectReference): ?string)|null  $resolver */
    public function fallbackUsing(?callable $resolver): void
    {
        $this->fallbackResolver = $resolver === null ? null : $resolver(...);
    }

    private ?\Closure $fallbackResolver = null;

    private function slug(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    // --------------------------------------------------------------- internals

    /**
     * @param  array{value: int|null, rank: int, holder: string, grant: Entitlement}  $candidate
     * @param  array{value: int|null, rank: int, holder: string, grant: Entitlement}  $best
     */
    private function beats(array $candidate, array $best): bool
    {
        if ($candidate['value'] !== $best['value']) {
            if ($candidate['value'] === null) {
                return true;
            }

            if ($best['value'] === null) {
                return false;
            }

            return $candidate['value'] > $best['value'];
        }

        if ($candidate['rank'] !== $best['rank']) {
            return $candidate['rank'] < $best['rank'];
        }

        // Several teams at equal height: the smallest subject key, byte by
        // byte ("team:10" before "team:9"), whatever order they arrived in.
        // Grants are read ordered by id, so within one holder the oldest
        // grant stays.
        return strcmp($candidate['holder'], $best['holder']) < 0;
    }

    private function readUsed(Quota $quota): int
    {
        // Silent on purpose: a read before the migration ran answers 0, and
        // only a write that cannot happen is worth a log line.
        if ($quota->holder === null || ! $this->catalog->ready()) {
            return 0;
        }

        return (int) ($this->findCounter($quota)->used ?? 0);
    }

    private function findCounter(Quota $quota): ?Usage
    {
        if ($quota->holder === null) {
            return null;
        }

        return Usage::query()
            ->where('subject_type', $quota->holder->type)
            ->where('subject_id', $quota->holder->id)
            ->where('limit_key', $quota->key)
            ->where('period_key', self::periodKey($quota->periodStart))
            ->first();
    }

    /**
     * The counter row for this holder, key and period, created if missing.
     */
    private function counter(Quota $quota): Usage
    {
        if ($quota->holder === null) {
            throw new LogicException('A counter needs a holder.');
        }

        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');

        Usage::query()->insertOrIgnore([
            'brand_id' => app('brand-context')->currentId(),
            'subject_type' => $quota->holder->type,
            'subject_id' => $quota->holder->id,
            'limit_key' => $quota->key,
            'period_key' => self::periodKey($quota->periodStart),
            'used' => 0,
            'limit_value' => $quota->limit,
            'period_start' => $quota->periodStart?->format('Y-m-d H:i:s'),
            'period_end' => $quota->periodEnd?->format('Y-m-d H:i:s'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = $this->findCounter($quota);

        if ($row === null) {
            // Only possible when the brand scope hides the row just written,
            // which would be a brand-context defect. Refuse rather than book
            // against a counter nobody can read.
            Log::error('statamic-entitlements: a usage counter could not be read back after writing it.', [
                'holder' => $quota->holder->key(),
                'key' => $quota->key,
            ]);

            throw new LogicException('The usage counter could not be read back.');
        }

        return $row;
    }

    private function announceReached(Usage $row, Quota $quota, SubjectReference $subject, int $used, CarbonImmutable $now): void
    {
        $claimed = Usage::query()
            ->whereKey($row->getKey())
            ->whereNull('reached_at')
            ->update(['reached_at' => $now->format('Y-m-d H:i:s')]) === 1;

        if (! $claimed || $quota->holder === null || $quota->limit === null) {
            return;
        }

        $this->events->dispatch(new LimitReached(
            subject: $subject,
            holder: $quota->holder,
            key: $quota->key,
            limit: $quota->limit,
            used: $used,
            kind: $quota->kind,
            product: $quota->product,
            period: $quota->period,
            periodStart: $quota->periodStart,
            periodEnd: $quota->periodEnd,
            occurredAt: $now,
            brandId: (int) $row->brand_id,
        ));
    }

    private function clearReached(Usage $row, ?int $limit): void
    {
        $query = Usage::query()->whereKey($row->getKey())->whereNotNull('reached_at');

        if ($limit !== null) {
            $query->where('used', '<', $limit);
        }

        $query->update(['reached_at' => null]);
    }

    private function ready(): bool
    {
        if ($this->catalog->ready()) {
            return true;
        }

        Log::error('statamic-entitlements: the limit tables do not exist. Run `php artisan migrate`.');

        return false;
    }

    private function entitlements(): EntitlementManager
    {
        return app(EntitlementManager::class);
    }
}

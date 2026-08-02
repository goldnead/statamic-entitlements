<?php

namespace Goldnead\Entitlements\Console\Commands;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\Entitlements\EntitlementManager;
use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Events\EntitlementExpired;
use Goldnead\Entitlements\Events\EntitlementGranted;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\StateResolver;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;

/**
 * Announces the two state transitions nothing writes.
 *
 * Four of this package's six states change because somebody did something: a
 * grant is written, a confirmation lands, a revocation is issued. Two change
 * because the clock moved.
 *
 *  - A {@see EntitlementState::Scheduled} grant becomes Active when `starts_at`
 *    arrives. Nothing writes at that moment, so `EntitlementGranted` has to come
 *    from here — the extraction spec is explicit that a scheduled grant gets its
 *    event at the actual start, not when it was promised.
 *  - An Active grant becomes Expired when `expires_at` passes (or when a grace
 *    period runs out). Same problem, same answer.
 *
 * ## Why it is safe to run every minute
 *
 * `announced_state` records what was last announced for each row, and the claim
 * is a conditional UPDATE whose affected-row count decides whether this process
 * fires the event. Two overlapping runs — a slow one and the next cron tick —
 * cannot both announce the same transition, because only one of their UPDATEs
 * affects the row.
 *
 * Without that, every run would re-announce every grant that has ever expired,
 * which for a listener that sends mail means a customer receiving the same
 * "your access has ended" notice once a minute forever.
 *
 * ## Why it is chunked
 *
 * The candidate query is bounded and ordered by id, and the loop stops after
 * `--limit`. A first run against a table that has been accumulating expired
 * grants for a year would otherwise dispatch tens of thousands of events inside
 * one command, and whatever listens to them would be the thing that fell over.
 */
class AnnounceStateTransitions extends Command
{
    use RunsForEachBrand;

    protected $signature = 'entitlements:announce
        {--limit=1000 : Maximum transitions to announce per brand}
        {--brand= : Restrict to one brand}';

    protected $description = 'Fire EntitlementGranted and EntitlementExpired for grants whose state changed with the clock.';

    public function handle(Dispatcher $events): int
    {
        $limit = max(1, (int) $this->option('limit'));

        return $this->forEachBrand(function () use ($events, $limit): int {
            $now = CarbonImmutable::now('UTC');

            $activated = $this->announceActivations($events, $now, $limit);
            $expired = $this->announceExpiries($events, $now, $limit);

            $this->line(sprintf('Announced %d activation(s) and %d expiry(ies).', $activated, $expired));

            return self::SUCCESS;
        });
    }

    /**
     * Grants that have reached their start date without anybody being told.
     *
     * `announced_state IS NULL` is the marker for "written as Scheduled and
     * never announced" — {@see EntitlementManager} leaves
     * it null for exactly that case and sets it for every other creation path.
     */
    private function announceActivations(Dispatcher $events, CarbonImmutable $now, int $limit): int
    {
        $count = 0;

        $candidates = StateResolver::constrain(
            Entitlement::query()->whereNull('announced_state'),
            EntitlementState::Active,
            $now,
        )->orderBy('id')->limit($limit)->get();

        foreach ($candidates as $entitlement) {
            if (! $this->claim($entitlement, null, EntitlementState::Active)) {
                continue;
            }

            $entitlement->refresh();

            $events->dispatch(new EntitlementGranted($entitlement, EntitlementState::Scheduled));

            $count++;
        }

        return $count;
    }

    private function announceExpiries(Dispatcher $events, CarbonImmutable $now, int $limit): int
    {
        $count = 0;

        $candidates = StateResolver::constrain(
            Entitlement::query()->where(fn (Builder $q) => $q
                ->whereNull('announced_state')
                ->orWhere('announced_state', '!=', EntitlementState::Expired->value)
            ),
            EntitlementState::Expired,
            $now,
        )->orderBy('id')->limit($limit)->get();

        foreach ($candidates as $entitlement) {
            $previous = $entitlement->announced_state;

            if (! $this->claim($entitlement, $previous, EntitlementState::Expired)) {
                continue;
            }

            // Which of the two dates actually ended access. A listener that has
            // to re-derive this gets it wrong for grace periods, which are the
            // case where it matters.
            $until = $entitlement->status === EntitlementState::GracePeriod->value
                ? $entitlement->grace_until
                : $entitlement->expires_at;

            $entitlement->refresh();

            $events->dispatch(new EntitlementExpired($entitlement, $until));

            $count++;
        }

        return $count;
    }

    /**
     * The claim. `=== 1` is the whole concurrency story: whoever's UPDATE
     * changes the row announces, everybody else moves on.
     */
    private function claim(Entitlement $entitlement, ?string $from, EntitlementState $to): bool
    {
        $query = Entitlement::query()->whereKey($entitlement->getKey());

        $from === null
            ? $query->whereNull('announced_state')
            : $query->where('announced_state', $from);

        return $query->update([
            'announced_state' => $to->value,
            'updated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
        ]) === 1;
    }
}

<?php

namespace Goldnead\Entitlements\Integrations\Insights;

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\TableMetric;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How many grants are live at the end of the period.
 *
 * ## A stock is not an event, and that is the whole of the difficulty
 *
 * Its three neighbours count things that happened: a grant began, a grant was
 * withdrawn, a grant ran out. Each of those has a timestamp, falls in exactly
 * one bucket, and adds up over a period — a month's grants are the sum of its
 * days.
 *
 * This one counts things that *are*. There is no "became active" column to
 * group by, because a grant does not become active by being written to: it
 * becomes active because a date passed, and it stops being active because
 * another one did. Nothing is recorded at either moment. So the question
 * "how many were active" can only be asked **of an instant**, and the answer
 * for a period is the answer for the instant the period ends:
 *
 *     starts_at  <= T
 *     AND (expires_at IS NULL OR expires_at > T)
 *     AND (revoked_at IS NULL OR revoked_at > T)
 *
 * Three properties follow, and each of them is a way somebody could read this
 * number wrongly:
 *
 *  - **It does not add up.** Twelve monthly buckets of 40 active grants are not
 *    480 grants; they are the same 40 seen twelve times. The chart is a level,
 *    not a bar of income.
 *  - **It is not the sum of its neighbours.** Granted minus revoked minus
 *    expired is the *change* over the period, not the holding at the end of it.
 *    The holding includes everything that began before the period and has not
 *    ended yet.
 *  - **A grant that began inside the period and was withdrawn before it ended
 *    is not in the figure at all.** It was granted (its own number says so) and
 *    it was revoked (so does its own number), and at the moment we are asking
 *    about, it is not live. That is the case the test suite pins.
 *
 * ## How the series is built
 *
 * Naïvely: one query per bucket, each asking the four conditions above at that
 * bucket's end. Ninety-two days of chart is ninety-two round trips, which is
 * the sort of thing that is fine in a test and unpleasant on a dashboard.
 *
 * So it is done as a running balance instead, which is the same arithmetic in
 * four queries regardless of how many buckets there are: the holding just
 * before the period begins, plus what arrived in each bucket, minus what left
 * in each bucket. A row arrives at `starts_at` and leaves at whichever of
 * `expires_at` and `revoked_at` comes first — which is why the departures are
 * two queries rather than one. `LEAST()` would have been one, and SQLite spells
 * it `MIN()`; two disjoint conditions are the same answer in every dialect this
 * family supports.
 *
 * Buckets whose balance is zero are left out, exactly as the contract asks:
 * Insights fills an omitted bucket with zero, and for a level "no bar" and
 * "zero active" are the same statement. That is only true because this is a
 * count — a *rate* could not be omitted, which is why the rate metrics in this
 * family return an explicit null instead.
 *
 * ## What it deliberately does not ask
 *
 * `grace_until` is not consulted: a grant inside its grace period still lets
 * its holder in, but its window has closed, and this figure is about windows.
 * `status` is consulted only to throw out a row that says `revoked` while
 * carrying no `revoked_at` — a revocation half-written by an interrupted
 * process, which the state resolver already fails closed on. Everything else
 * about the current `status` column is a fact about *now*, and applying it to a
 * bucket six months back would rewrite history.
 */
class Active extends EntitlementMetric
{
    /**
     * A hard stop on how many buckets a chart may have.
     *
     * The period can be open-ended ("all"), in which case the range is however
     * far back the oldest grant goes. Insights picks monthly buckets for
     * anything past a quarter, so this is only ever reached by a caller that
     * asks for daily grain over a decade — and a chart of four thousand columns
     * is not a chart. The recent end is kept, because that is the end anybody
     * looking at a level cares about.
     */
    private const MAX_BUCKETS = 1200;

    /**
     * The column a row enters the stock on.
     *
     * Declared because {@see TableMetric}
     * requires it, and it is genuinely the arrival marker — but nothing in this
     * class windows on it the way an event metric does. Leaving is a second
     * column, and the whole point of the class is the two together.
     */
    protected function timestamp(): string
    {
        return 'starts_at';
    }

    public function handle(): string
    {
        return 'entitlements.active';
    }

    public function label(): string
    {
        return __('entitlements::insights.metric_active');
    }

    public function description(): ?string
    {
        return __('entitlements::insights.metric_active_description');
    }

    public function unit(): string
    {
        return Unit::COUNT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        return (int) $this->liveAt($this->closingInstant($query))->count();
    }

    /**
     * The holding at the end of every bucket, as a running balance.
     *
     * @return array<string, int>
     */
    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        [$from, $end] = $this->utcBounds($query);

        $start = $from ?? $this->firstArrival();

        if ($start === null || $start->greaterThan($end)) {
            return [];
        }

        $buckets = $this->bucketKeys($start, $end, $query);

        if ($buckets === []) {
            return [];
        }

        $arrived = $this->countPerBucket($this->arrivedBetween($start, $end), $query, 'starts_at');
        $left = $this->countPerBucket($this->expiredBetween($start, $end), $query, 'expires_at');

        foreach ($this->countPerBucket($this->revokedBetween($start, $end), $query, 'revoked_at') as $bucket => $count) {
            $left[$bucket] = ($left[$bucket] ?? 0) + $count;
        }

        // Everything that was already live when the period opened. Without it
        // the chart would start at zero and climb, which is what a stock metric
        // looks like when somebody forgot it had a past.
        $running = $from === null ? 0 : $this->liveJustBefore($start);

        $series = [];

        foreach ($buckets as $bucket) {
            $running += ($arrived[$bucket] ?? 0) - ($left[$bucket] ?? 0);

            // Zero is omitted, not written. Insights fills a missing bucket with
            // zero, and for a level that is the same statement — see the class
            // docblock on why a rate could not do this.
            if ($running !== 0) {
                $series[$bucket] = $running;
            }
        }

        return $series;
    }

    // -- The queries --------------------------------------------------------

    /**
     * Grants that are live at one instant.
     *
     * The definition, in one place, used by `value()` and by the opening
     * balance. Everything else in this class is an optimisation of it.
     */
    protected function liveAt(Carbon $moment): Builder
    {
        return $this->rows()
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', $moment)
            ->where(fn (Builder $rows) => $rows->whereNull('expires_at')->orWhere('expires_at', '>', $moment))
            ->where(fn (Builder $rows) => $rows->whereNull('revoked_at')->orWhere('revoked_at', '>', $moment));
    }

    /**
     * The holding an instant before the period opens.
     *
     * `>=` against the period start rather than `>` against the instant before
     * it: timestamps here are stored to the second, so "still live at
     * 23:59:59 on the day before" and "does not end before the period begins"
     * select the same rows, and the second does not need an instant that
     * depends on the column's precision.
     */
    protected function liveJustBefore(Carbon $start): int
    {
        return (int) $this->rows()
            ->whereNotNull('starts_at')
            ->where('starts_at', '<', $start)
            ->where(fn (Builder $rows) => $rows->whereNull('expires_at')->orWhere('expires_at', '>=', $start))
            ->where(fn (Builder $rows) => $rows->whereNull('revoked_at')->orWhere('revoked_at', '>=', $start))
            ->count();
    }

    protected function arrivedBetween(Carbon $start, Carbon $end): Builder
    {
        return $this->rows()
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [$start, $end]);
    }

    /**
     * Rows that left by running out, and were not withdrawn first.
     *
     * Disjoint with {@see revokedBetween()} by construction: where both dates
     * exist, exactly one of `revoked_at > expires_at` and
     * `revoked_at <= expires_at` holds, so no row is subtracted twice and none
     * is missed.
     */
    protected function expiredBetween(Carbon $start, Carbon $end): Builder
    {
        return $this->rows()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$start, $end])
            ->where(fn (Builder $rows) => $rows
                ->whereNull('revoked_at')
                ->orWhereColumn('revoked_at', '>', 'expires_at'));
    }

    /** Rows that left by being withdrawn, at or before they would have run out. */
    protected function revokedBetween(Carbon $start, Carbon $end): Builder
    {
        return $this->rows()
            ->whereNotNull('revoked_at')
            ->whereBetween('revoked_at', [$start, $end])
            ->where(fn (Builder $rows) => $rows
                ->whereNull('expires_at')
                ->orWhereColumn('revoked_at', '<=', 'expires_at'));
    }

    /**
     * Every row this figure is willing to consider.
     *
     * One exclusion, and it mirrors the state resolver: a row whose `status`
     * says `revoked` while `revoked_at` is empty is a revocation that was
     * half-written, and the resolver fails closed on it. Counting it as a live
     * grant here would put a number on a dashboard that contradicts what the
     * access check tells the customer.
     */
    protected function rows(): Builder
    {
        // Brand-scoped by hand, because this is the one metric here that does
        // not go through `inPeriod()` at all: a stock is asked of an instant,
        // not of a window. `brandScoped()` is the same narrowing the base class
        // applies there, so the level agrees with the three event figures
        // beside it instead of quietly counting a brand they exclude.
        return $this->brandScoped(DB::table($this->table()))
            ->where(fn (Builder $rows) => $rows
                ->where('status', '!=', EntitlementState::Revoked->value)
                ->orWhereNotNull('revoked_at'));
    }

    // -- Bucketing ----------------------------------------------------------

    /**
     * @return array<string, int>
     */
    protected function countPerBucket(Builder $rows, MetricQuery $query, string $column): array
    {
        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($rows, $query, 'count(*)', $column),
        );
    }

    /**
     * Every bucket key the period covers, in order.
     *
     * Enumerated in PHP rather than read off the data, because a stock has a
     * value in a bucket where nothing at all happened — that is the difference
     * between a level and an event, and a series built from the buckets that
     * have rows would draw a flat month as an absent one.
     *
     * @return array<int, string>
     */
    protected function bucketKeys(Carbon $start, Carbon $end, MetricQuery $query): array
    {
        $monthly = $query->bucket === MetricQuery::BUCKET_MONTH;

        $cursor = $monthly
            ? $start->copy()->startOfMonth()
            : $start->copy()->startOfDay();

        $last = $monthly
            ? $end->copy()->startOfMonth()
            : $end->copy()->startOfDay();

        $keys = [];

        while ($cursor->lessThanOrEqualTo($last)) {
            $keys[] = $cursor->format($monthly ? 'Y-m' : 'Y-m-d');
            $monthly ? $cursor->addMonth() : $cursor->addDay();
        }

        // The recent end is the one that matters for a level; an open-ended
        // period asked at daily grain is trimmed at the front rather than
        // refused.
        return count($keys) > self::MAX_BUCKETS
            ? array_slice($keys, -self::MAX_BUCKETS)
            : $keys;
    }

    /**
     * The instant the question is asked at, in the zone the columns are in.
     *
     * An open-ended period ("all") has no end, and "how many are active, ever"
     * is not a question — a stock needs a moment. Now is that moment.
     */
    protected function closingInstant(MetricQuery $query): Carbon
    {
        return $this->utcBounds($query)[1];
    }

    /** The oldest beginning in the table, for a period that names no start. */
    protected function firstArrival(): ?Carbon
    {
        $oldest = $this->rows()->whereNotNull('starts_at')->min('starts_at');

        // Parsed as UTC, because that is what the column holds — reading it in
        // the application timezone would move the first bucket of an open-ended
        // chart by the site's offset.
        return $oldest === null ? null : Carbon::parse((string) $oldest, 'UTC');
    }
}

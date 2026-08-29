<?php

namespace Goldnead\Entitlements\Integrations\Insights;

use Goldnead\BrandContext\Scopes\BrandScope;
use Goldnead\Entitlements\Casts\UtcDateTime;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\TableMetric;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * What every figure this addon offers the analytics addon has in common.
 *
 * The coupling runs one way and is optional in both directions:
 * `goldnead/statamic-insights` is a `suggest`, never a `require`, and the
 * classes in this directory are only ever loaded once the service provider has
 * seen the sibling's facade. Loading this file *means* the sibling is
 * installed — {@see TableMetric} lives in its package.
 *
 * Four decisions shape every number in this directory.
 *
 * 1. **Timestamps are chosen, never defaulted.** There is no `created_at`
 *    metric here. A grant row is written when the software noticed; a grant
 *    *begins* when `starts_at` says it does, and the two differ by however long
 *    a double opt-in sat unconfirmed. Each metric names its column and says
 *    why.
 * 2. **A row with no timestamp is not a row.** `starts_at`, `revoked_at` and
 *    `expires_at` are all nullable, and Insights' widest period ("all") passes
 *    no bounds at all — so without an explicit `whereNotNull` a pending grant
 *    would be counted as a grant that began, on no day in particular. The
 *    period bounds hide that: `NULL >= '2026-08-01'` is never true. It only
 *    appears on the open-ended period, which is the one nobody tests by hand.
 * 3. **The numbers stop at the brand boundary.** `entitlements.brand_id` is on
 *    every row, and {@see brandColumn()} declares it so that the base class
 *    narrows the figure, the chart and every split by the same rules the rest
 *    of the install reads this table with. It was the other way round once, on
 *    the grounds that Insights asks its question once per installation — but a
 *    tile that counts four customers, beside tiles that count one, with nothing
 *    on either saying which, is a leak rather than a wider view. Single-brand
 *    installs are untouched: there the filter adds no condition at all.
 * 4. **Nothing to measure is not measuring nothing.** {@see available()} is
 *    false without the table and false when the bridge is switched off, so the
 *    figures vanish from every screen instead of reporting a confident zero for
 *    an addon that is not in use.
 *
 * ## The window is converted to UTC, and it has to be
 *
 * This package stores every one of its four timestamps in UTC on disk,
 * unconditionally — see {@see UtcDateTime} and
 * the reason it exists: each of those columns decides whether a paying customer
 * gets in, so a two-hour slip is not a formatting bug.
 *
 * Insights, on the other hand, builds its period from `Carbon::now()`, which is
 * in the **application** timezone. Handed straight to the query builder, a
 * bound is formatted in its own zone and compared against a UTC column, and
 * every figure here would be quietly shifted by the site's offset — five hours
 * on the machine this suite runs on, and enough to move a grant made late on
 * the last evening of a month into the next one. So the window is restated in
 * UTC before it touches a query, in {@see inUtc()}, once, for all four metrics —
 * and the clamp on "now" with it, in {@see untilNow()}.
 *
 * That restatement is the *whole* of what this package adds to
 * {@see TableMetric::inPeriod()}. Everything else about the window is inherited,
 * deliberately: the three repairs that class has taken — the null timestamp, the
 * half-open upper bound, the clamp on the future — reached only the metrics that
 * called `parent::inPeriod()`, and a copy of it here would miss the fourth.
 *
 * What is *not* corrected is the bucket grain: the day a row is filed under is
 * its UTC day, because the truncation happens in SQL and doing it per timezone
 * would need a different expression in each of the three dialects
 * {@see TableMetric} supports. A grant made after 19:00 Chicago time therefore
 * appears on the following day's column. The period totals are exact; a single
 * bar near midnight may sit one place to the right.
 */
abstract class EntitlementMetric extends TableMetric
{
    protected function table(): string
    {
        return 'entitlements';
    }

    public function group(): string
    {
        return __('entitlements::insights.metric_group');
    }

    /**
     * The table has to be there, and the bridge has to be wanted.
     *
     * `entitlements.bridges.insights` is the same switch shape the activity
     * bridge uses. An installation that has the analytics addon for something
     * else entirely can turn these four figures off without uninstalling
     * anything.
     */
    public function available(): bool
    {
        return (bool) config('entitlements.bridges.insights', true) && parent::available();
    }

    /**
     * Which brand a row belongs to.
     *
     * Declared, not filtered by hand: {@see TableMetric::inPeriod()} narrows the
     * figure, the chart and every split at once, by exactly the rules the rest
     * of the install reads this table with
     * ({@see BrandScope}), so no metric here can
     * forget one of the three.
     *
     * It used to be deliberately unfiltered, on the grounds that Insights asks
     * its question once per installation. That was the wrong answer: on a screen
     * next to tiles that do stop at the brand boundary, a tile that does not is
     * one customer's numbers shown to another. In single-brand mode — which is
     * every ordinary install — this narrows nothing at all.
     */
    protected function brandColumn(): ?string
    {
        return 'brand_id';
    }

    /**
     * The window, in the zone the columns are actually stored in, and never
     * open at the far end.
     *
     * The start may be absent — "all time" genuinely has no beginning. The end
     * may not: an unbounded upper edge would count things that have not
     * happened yet, and this table is full of dates in the future. A pre-sale
     * carries a `starts_at` next month, a licence an `expires_at` next year, an
     * editor can schedule a withdrawal. On the "all" preset those would be
     * reported as grants that have begun, licences that have run out and
     * customers who have been cut off — none of which is true yet, and all of
     * which look ordinary on a dashboard.
     *
     * So "all" means everything **so far**. Now is the far edge — which is what
     * {@see untilNow()} puts there, and why every event figure here asks for
     * that window rather than for {@see inPeriod()}.
     *
     * Kept as its own accessor because {@see Active} needs the two instants
     * themselves rather than a query: a stock is asked *of a moment*, and that
     * moment is the end of this window.
     *
     * @return array{0: ?Carbon, 1: Carbon}
     */
    protected function utcBounds(MetricQuery $query): array
    {
        return [
            $query->period->from?->copy()->utc(),
            $query->period->to?->copy()->utc() ?? Carbon::now('UTC'),
        ];
    }

    /**
     * The same question, with its window stated in UTC.
     *
     * The one thing this package has to do to the period before it touches a
     * column, and the reason it is done to the *question* rather than to the
     * query: everything else — the null timestamp, the half-open upper bound,
     * the brand — is then the base class's, once, for every figure here.
     *
     * A binding is formatted in the zone its own Carbon carries, so a bound
     * moved to UTC compares correctly against a column stored in UTC. The
     * instant is unchanged; only its spelling is.
     *
     * An open-ended period has no bounds to convert and is handed on as it came.
     */
    protected function inUtc(MetricQuery $query): MetricQuery
    {
        if ($query->period->from === null || $query->period->to === null) {
            return $query;
        }

        return new MetricQuery(
            Period::between(
                $query->period->from->copy()->utc(),
                $query->period->to->copy()->utc(),
            ),
            $query->bucket,
            $query->filters,
        );
    }

    /**
     * The rows inside the window.
     *
     * Extended rather than rewritten. The whole of the override is the line
     * above it: the bounds are stated in UTC first, and everything else — the
     * `whereNotNull`, the half-open upper bound, the brand — belongs to
     * {@see TableMetric::inPeriod()} and arrives here by inheritance.
     *
     * It was a full copy once, and cost all three of those repairs: the copy
     * kept comparing `<= 23:59:59.999999`, which a binding truncates to the
     * second, so every row in the last second of a period silently fell out.
     */
    protected function inPeriod(MetricQuery $query, ?string $column = null): Builder
    {
        return parent::inPeriod($this->inUtc($query), $column);
    }

    /**
     * Every column this package counts on is UTC on disk, unconditionally.
     *
     * Stating it is all this takes: {@see TableMetric::untilNow()} closes the
     * far end of the window on this clock instead of the site's. Clamped on the
     * site's clock, a Chicago install lost five hours of the most recent grants
     * from every figure and a Berlin one counted two hours of the future —
     * invisible to whoever wrote the metric, because on a UTC site both clocks
     * agree.
     *
     * This used to be a restated `untilNow()` here. It is one line upstream now,
     * which is the difference between a fix that reaches the family and a fix
     * that reaches whoever remembered to copy it.
     */
    protected function zone(): ?string
    {
        return 'UTC';
    }

    /**
     * The words for a row that has no value in the dimension it is split by.
     *
     * Per dimension, because "no source" and "no reason given" read differently
     * and a shared dash tells a reader nothing. `product` is here for
     * completeness — `product_slug` is NOT NULL, so the row should not exist;
     * if it ever does, it gets a name rather than an empty cell.
     */
    protected function missingLabel(string $dimension): string
    {
        return __('entitlements::insights.metric_no_'.$dimension);
    }
}

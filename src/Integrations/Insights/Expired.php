<?php

namespace Goldnead\Entitlements\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Database\Query\Builder;

/**
 * How many grants ran out.
 *
 * On `expires_at`, which is the moment the window closed rather than the moment
 * anybody noticed — nothing is written when a grant expires, and nothing needs
 * to be. Expiry in this package is derived from the clock, so the column *is*
 * the event.
 *
 * **A grant that was revoked before it would have expired does not expire.**
 * It was taken away, which is the neighbouring figure, and counting it in both
 * would report one lost access as two. The guard is
 * `revoked_at IS NULL OR revoked_at > expires_at`: a revocation *after* the
 * expiry — an editor tidying up a window that had already closed — leaves the
 * expiry standing, because that is what really ended the access.
 *
 * `grace_until` is deliberately not consulted. A grace period keeps access
 * alive past the expiry, so for a handful of rows this figure names the end of
 * the *paid* window rather than the end of access. That is the question worth
 * asking of a renewal report, and folding grace in would make the number mean
 * two different things depending on which row it counted.
 */
class Expired extends EntitlementMetric
{
    protected function timestamp(): string
    {
        return 'expires_at';
    }

    public function handle(): string
    {
        return 'entitlements.expired';
    }

    public function label(): string
    {
        return __('entitlements::insights.metric_expired');
    }

    public function description(): ?string
    {
        return __('entitlements::insights.metric_expired_description');
    }

    public function unit(): string
    {
        return Unit::COUNT;
    }

    /**
     * Rows whose window closed inside the period, and closed by running out.
     *
     * The base refuses the rows with no `expires_at` at all — a lifetime
     * licence is the normal shape of one, and the open-ended period would
     * otherwise report every one of them as having expired. What is added here
     * is only the second half: it has to have ended *by expiring*.
     *
     * The condition sits on `inPeriod()` rather than on the two callers, so it
     * reaches the figure and the chart alike — including through
     * `untilNow()`, which is the window this metric is actually read over and
     * which builds on this one.
     */
    protected function inPeriod(MetricQuery $query, ?string $column = null): Builder
    {
        $column ??= $this->timestamp();

        return parent::inPeriod($query, $column)
            ->where(fn (Builder $rows) => $rows
                ->whereNull('revoked_at')
                ->orWhereColumn('revoked_at', '>', $column));
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        return (int) $this->untilNow($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->untilNow($query), $query, 'count(*)'),
        );
    }
}

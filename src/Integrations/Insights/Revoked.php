<?php

namespace Goldnead\Entitlements\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many grants were taken away, and on what grounds.
 *
 * On `revoked_at`, which is the only date that means anything here: a grant
 * issued in January and revoked in March belongs to March, because March is
 * when the customer lost access and when somebody had to explain why.
 *
 * The split is by `revoked_reason`, and it is the reason this figure is worth
 * more than a count. The addon refuses a revocation without one — a withdrawal
 * nobody can explain six months later is one that whoever is on support that
 * day undoes — so the column is populated for everything this package writes.
 * A null still gets a row rather than being dropped: a row written past the
 * manager, or migrated in from a system that had no such rule, is exactly what
 * a reader needs to see.
 */
class Revoked extends EntitlementMetric implements HasBreakdowns
{
    protected function timestamp(): string
    {
        return 'revoked_at';
    }

    public function handle(): string
    {
        return 'entitlements.revoked';
    }

    public function label(): string
    {
        return __('entitlements::insights.metric_revoked');
    }

    public function description(): ?string
    {
        return __('entitlements::insights.metric_revoked_description');
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

    public function breakdowns(): array
    {
        return ['reason' => __('entitlements::insights.metric_breakdown_reason')];
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available() || $dimension !== 'reason') {
            return [];
        }

        return $this->labelled(
            $this->splitByColumn($this->untilNow($query), $query, 'revoked_reason', 'count(*)', $limit),
            $dimension,
        );
    }
}

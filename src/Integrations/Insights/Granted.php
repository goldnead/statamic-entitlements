<?php

namespace Goldnead\Entitlements\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\TableMetric;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many grants began.
 *
 * **On `starts_at`, not on `created_at`.** The row exists from the moment
 * somebody asked; access exists from the moment `starts_at` says it does, and
 * between those two lies every unconfirmed double opt-in and every pre-sale
 * with a start date. Counting rows written would answer "how many people filled
 * in a form", which is a different question and one the lead-magnet addon
 * already answers.
 *
 * Three consequences, all deliberate:
 *
 *  - A **pending** grant has no `starts_at` at all (`grantPending()` writes
 *    null on purpose) and is therefore not counted anywhere until it is
 *    claimed. On the day it is claimed it appears here, which is the day access
 *    actually started.
 *  - A **scheduled** grant — one whose `starts_at` is in the future — is filed
 *    forward and counts on the day it actually begins, not before. That is what
 *    {@see TableMetric::untilNow()} buys: the widest period on the screen means
 *    everything *so far*, and a pre-sale starting next month is not a grant that
 *    has begun. It is not lost; it appears on its own date, once that date is
 *    behind us.
 *  - A grant that was later revoked still counts here. It began; the
 *    revocation is its own figure. Netting the two into one number would hide
 *    a month in which fifty grants were issued and forty-nine taken back.
 */
class Granted extends EntitlementMetric implements HasBreakdowns
{
    protected function timestamp(): string
    {
        return 'starts_at';
    }

    public function handle(): string
    {
        return 'entitlements.granted';
    }

    public function label(): string
    {
        return __('entitlements::insights.metric_granted');
    }

    public function description(): ?string
    {
        return __('entitlements::insights.metric_granted_description');
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
        return [
            'product' => __('entitlements::insights.metric_breakdown_product'),
            'source' => __('entitlements::insights.metric_breakdown_source'),
        ];
    }

    /**
     * Split by what was granted, or by where the grant came from.
     *
     * `source` is a free string by design — an installation invents its own
     * (`thrivecart`, `mollie`, `lead_magnet`, `manual`) and
     * `config('entitlements.sources')` is a display registry rather than a
     * whitelist. So the split is over whatever is really in the column, and a
     * source nobody registered shows its raw handle rather than disappearing.
     */
    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available()) {
            return [];
        }

        $column = match ($dimension) {
            'product' => 'product_slug',
            'source' => 'source',
            default => null,
        };

        if ($column === null) {
            return [];
        }

        return $this->labelled(
            $this->splitByColumn($this->untilNow($query), $query, $column, 'count(*)', $limit),
            $dimension,
        );
    }
}

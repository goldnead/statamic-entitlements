<?php

namespace Goldnead\Entitlements\Events;

use Carbon\CarbonImmutable;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A limit is full.
 *
 * For a usage limit this fires from the one booking that takes the counter to
 * the limit — exactly once per period, because the flag it sets is claimed by a
 * conditional UPDATE. For a stock limit it fires when the count a caller
 * reports reaches the limit, and again only after the count has dropped below
 * it in between.
 *
 * `subject` is who asked, `holder` is whose grant the limit belongs to and
 * where usage is counted. They differ when a team member uses the team's plan.
 *
 * Only scalars and references: a listener may queue this, and a queued event
 * must not carry a model that is gone by the time the worker reads it.
 */
class LimitReached
{
    use Dispatchable;

    public function __construct(
        public readonly SubjectReference $subject,
        public readonly SubjectReference $holder,
        public readonly string $key,
        public readonly int $limit,
        public readonly int $used,
        public readonly string $kind,
        public readonly ?string $product = null,
        public readonly ?string $period = null,
        public readonly ?CarbonImmutable $periodStart = null,
        public readonly ?CarbonImmutable $periodEnd = null,
        public readonly ?CarbonImmutable $occurredAt = null,
        public readonly ?int $brandId = null,
    ) {}
}

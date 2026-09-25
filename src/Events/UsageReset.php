<?php

namespace Goldnead\Entitlements\Events;

use Carbon\CarbonImmutable;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\IdentityContracts\Identity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A usage counter starts from zero again.
 *
 * Two causes, told apart by `reason`:
 *  - `period`: the period ended (`entitlements:announce` notices it, once per
 *    period and holder), so the next booking opens a fresh counter.
 *  - `manual`: somebody reset it in the Control Panel or through the API;
 *    `actor` says who.
 *
 * `previous` is what had been used when the counter was closed.
 */
class UsageReset
{
    use Dispatchable;

    public const REASON_PERIOD = 'period';

    public const REASON_MANUAL = 'manual';

    public function __construct(
        public readonly SubjectReference $holder,
        public readonly string $key,
        public readonly int $previous,
        public readonly string $reason,
        public readonly ?string $period = null,
        public readonly ?CarbonImmutable $periodStart = null,
        public readonly ?CarbonImmutable $periodEnd = null,
        public readonly ?Identity $actor = null,
        public readonly ?CarbonImmutable $occurredAt = null,
        public readonly ?int $brandId = null,
    ) {}
}

<?php

namespace Goldnead\Entitlements\Events;

use Carbon\CarbonImmutable;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A booking against a usage limit went through.
 *
 * Fires only for a booking that was accepted; a refused one changed nothing.
 * `used` is the counter right after this booking, read back from the row, so a
 * listener does not have to add anything up. `limit` null means unlimited.
 */
class UsageConsumed
{
    use Dispatchable;

    public function __construct(
        public readonly SubjectReference $subject,
        public readonly SubjectReference $holder,
        public readonly string $key,
        public readonly int $amount,
        public readonly int $used,
        public readonly ?int $limit,
        public readonly ?string $product = null,
        public readonly ?string $period = null,
        public readonly ?CarbonImmutable $periodStart = null,
        public readonly ?CarbonImmutable $periodEnd = null,
        public readonly ?CarbonImmutable $occurredAt = null,
        public readonly ?int $brandId = null,
    ) {}
}

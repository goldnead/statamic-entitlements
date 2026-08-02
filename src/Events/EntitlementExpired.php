<?php

namespace Goldnead\Entitlements\Events;

use Carbon\CarbonImmutable;
use Goldnead\Entitlements\Console\Commands\AnnounceStateTransitions;
use Goldnead\Entitlements\Models\Entitlement;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A grant's window has closed.
 *
 * The odd one of the four: expiry is the only transition that nothing writes.
 * A grant expires because the clock moved past `expires_at`, and no request,
 * job or button was involved — so there is no write to hang the event on.
 *
 * It therefore comes from a scheduled pass ({@see AnnounceStateTransitions}),
 * and the pass has to be idempotent or every run would re-announce every grant
 * that ever expired. The marker is a dedicated column, `announced_state`,
 * claimed with a conditional UPDATE before the event fires.
 *
 * The rejected alternative was flipping `status` to `expired`. It needs no extra
 * column, and that is its only advantage: it would make the stored status a
 * second source of truth about state, so a row could then be "expired" by status
 * and Active by its dates. One column is cheaper than an ambiguous state
 * machine.
 *
 * `$grantedAccessUntil` is the instant access actually ended — `grace_until`
 * when a grace period was in play, `expires_at` otherwise — so a listener does
 * not have to re-derive which of the two applied.
 */
class EntitlementExpired
{
    use Dispatchable;

    public function __construct(
        public readonly Entitlement $entitlement,
        public readonly ?CarbonImmutable $grantedAccessUntil = null,
    ) {}
}

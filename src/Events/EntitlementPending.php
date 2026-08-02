<?php

namespace Goldnead\Entitlements\Events;

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\IdentityContracts\Identity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A grant has been parked without access, waiting for a confirmation.
 *
 * The confirm-first pattern: a lead magnet is claimed, the grant is written, and
 * nothing is handed over until the double opt-in comes back. A listener here is
 * where a consumer sends the confirmation request — this addon sends nothing.
 *
 * Fires only on the transition into Pending, so a contact who re-submits the
 * same form while a confirmation is already open does not trigger a second
 * request. The grant is idempotent on its tuple and so is this event.
 */
class EntitlementPending
{
    use Dispatchable;

    public function __construct(
        public readonly Entitlement $entitlement,
        public readonly ?EntitlementState $previousState = null,
        public readonly ?Identity $actor = null,
    ) {}
}

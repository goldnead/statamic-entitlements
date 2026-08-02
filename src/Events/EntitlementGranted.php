<?php

namespace Goldnead\Entitlements\Events;

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\IdentityContracts\Identity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A grant has become {@see EntitlementState::Active}.
 *
 * Fired when a grant is written straight to Active, and again when one that was
 * {@see EntitlementState::Pending} wins the atomic claim. Not fired for a grant
 * created with a future `starts_at`: that one is Scheduled, and a promise is not
 * an event. It gets its EntitlementGranted when the clock reaches it.
 *
 * ## What a listener may assume
 *
 * That the row is committed and Active at the moment of firing, and that this
 * event fires **once per transition**, not once per call. The write paths use a
 * conditional UPDATE with an affected-row check, so a retried job or a
 * double-clicked button produces one event, not two.
 *
 * ## What this addon deliberately does not do
 *
 * Send anything. No welcome mail, no magic link, no account creation. In the
 * system this was extracted from, all three lived inside the provisioning call
 * that wrote the grant, which is why a mail failure could roll back a paid-for
 * entitlement. They belong in the consuming application, on a listener here.
 *
 * Registering that listener synchronously reproduces the old ordering exactly:
 * the mail still goes out inside the same request, after the grant is written.
 * That is a condition of the migration, not a detail.
 */
class EntitlementGranted
{
    use Dispatchable;

    public function __construct(
        public readonly Entitlement $entitlement,
        public readonly ?EntitlementState $previousState = null,
        public readonly ?Identity $actor = null,
    ) {}
}

<?php

namespace Goldnead\Entitlements\Events;

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\IdentityContracts\Identity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Access has been taken away deliberately.
 *
 * Carries the reason, because a revocation nobody can explain later is a
 * revocation somebody undoes. The reason is required at the API and at the
 * Control Panel form; it is not an optional note.
 *
 * `$actor` is the point of the whole event for an audit trail: a chargeback
 * handled by a webhook and a refund granted by a person are the same row and
 * very different facts. It arrives as an {@see Identity} — a value object of
 * scalars — rather than a user model, because a listener may persist it and the
 * user record may be gone by the time anybody reads it back.
 *
 * Fires once per actual transition. Revoking an already-revoked grant is a
 * no-op and silent.
 */
class EntitlementRevoked
{
    use Dispatchable;

    public function __construct(
        public readonly Entitlement $entitlement,
        public readonly string $reason,
        public readonly ?EntitlementState $previousState = null,
        public readonly ?Identity $actor = null,
    ) {}
}

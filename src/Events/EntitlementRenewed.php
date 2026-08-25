<?php

namespace Goldnead\Entitlements\Events;

use Carbon\CarbonImmutable;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\IdentityContracts\Identity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An existing grant now runs longer.
 *
 * Deliberately not a second `EntitlementGranted`. A renewal is not a new fact
 * about access — the subject had it before and has it after — and a listener
 * that welcomes people would otherwise greet the same person every month.
 *
 * Carries the window it replaces, because "until when did they have it before"
 * is the question an audit asks and the row no longer answers.
 */
class EntitlementRenewed
{
    use Dispatchable;

    public function __construct(
        public readonly Entitlement $entitlement,
        public readonly ?CarbonImmutable $previousExpiresAt = null,
        public readonly ?Identity $actor = null,
    ) {}
}

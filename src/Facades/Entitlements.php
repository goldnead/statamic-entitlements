<?php

namespace Goldnead\Entitlements\Facades;

use Goldnead\Entitlements\EntitlementManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Goldnead\Entitlements\Models\Entitlement grant(mixed $subject, string $productSlug, string $source, ?string $sourceRef = null, ?\DateTimeInterface $startsAt = null, ?\DateTimeInterface $expiresAt = null, ?\DateTimeInterface $graceUntil = null, array $meta = [], ?\Goldnead\IdentityContracts\Identity $actor = null)
 * @method static \Goldnead\Entitlements\Models\Entitlement grantPending(mixed $subject, string $productSlug, string $source, ?string $sourceRef = null, ?\DateTimeInterface $expiresAt = null, array $meta = [], ?\Goldnead\IdentityContracts\Identity $actor = null)
 * @method static bool claimPending(\Goldnead\Entitlements\Models\Entitlement $entitlement, ?\Goldnead\IdentityContracts\Identity $actor = null)
 * @method static bool revoke(\Goldnead\Entitlements\Models\Entitlement $entitlement, string $reason, ?\Goldnead\IdentityContracts\Identity $actor = null)
 * @method static bool restore(\Goldnead\Entitlements\Models\Entitlement $entitlement, ?\Goldnead\IdentityContracts\Identity $actor = null)
 * @method static bool enterGracePeriod(\Goldnead\Entitlements\Models\Entitlement $entitlement, \DateTimeInterface $until)
 * @method static \Goldnead\Entitlements\Enums\EntitlementState stateOf(\Goldnead\Entitlements\Models\Entitlement $entitlement)
 * @method static \Goldnead\Entitlements\Support\AccessDecision decide(mixed $subject, string $productSlug)
 * @method static bool allows(mixed $subject, string $productSlug)
 * @method static list<string> activeProductSlugsFor(mixed $subject)
 * @method static \Illuminate\Database\Eloquent\Builder forSubject(mixed $subject)
 * @method static \Illuminate\Database\Eloquent\Builder query()
 * @method static \Goldnead\Entitlements\Support\SubjectReference reference(mixed $subject)
 * @method static string subjectLabel(\Goldnead\Entitlements\Support\SubjectReference $reference)
 *
 * @see EntitlementManager
 */
class Entitlements extends Facade
{
    /**
     * The FQCN, not a short string handle.
     *
     * A container key named after the addon slug is how `singleton('events')` in
     * a sibling package overwrote Laravel's own event dispatcher and took the
     * framework down at the next `listen()`. `entitlements` is less obviously
     * taken than `events`, which makes it more dangerous rather than less: the
     * failure would surface somewhere unrelated, weeks later.
     */
    protected static function getFacadeAccessor(): string
    {
        return EntitlementManager::class;
    }
}

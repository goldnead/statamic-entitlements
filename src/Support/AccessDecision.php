<?php

namespace Goldnead\Entitlements\Support;

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Models\Entitlement;
use JsonSerializable;

/**
 * Yes or no, plus a machine-readable reason.
 *
 * The shape is taken from the source system's `AccessDecisionService`, which was
 * twenty lines and the cleanest thing in the whole area: it answered the
 * question and delegated everything else. That restraint is kept — this is not a
 * policy engine, and inventing one here would mean designing a feature rather
 * than extracting one.
 *
 * `SUPER_USER` is deliberately absent from the reasons. The source checked
 * `$user->super` inside the decision, which is a host authorisation concern
 * wearing an entitlement's clothes: it binds the package to a specific user
 * model and it hides an override inside a domain answer. A consumer that wants
 * superusers to bypass entitlements checks that before asking.
 */
final readonly class AccessDecision implements JsonSerializable
{
    public const ENTITLED = 'ENTITLED';

    public const NOT_ENTITLED = 'NOT_ENTITLED';

    /**
     * @param  string  $reason  ENTITLED or NOT_ENTITLED.
     * @param  EntitlementState|null  $state  The state of the grant that decided it. On a refusal
     *                                        this is the *closest* grant found — Expired, Pending,
     *                                        Scheduled or Revoked — which is what a support person
     *                                        needs and what a bare boolean never tells them. Null
     *                                        means no grant exists at all.
     */
    private function __construct(
        public bool $allowed,
        public string $reason,
        public ?EntitlementState $state = null,
        public ?Entitlement $entitlement = null,
    ) {}

    public static function entitled(Entitlement $entitlement): self
    {
        return new self(true, self::ENTITLED, $entitlement->state(), $entitlement);
    }

    public static function refused(?Entitlement $closest = null): self
    {
        return new self(false, self::NOT_ENTITLED, $closest?->state(), $closest);
    }

    /**
     * The array shape the source system's callers already expect, so a consumer
     * can swap the service out without touching its call sites.
     *
     * @return array{allowed: bool, reason: string, state: string|null}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'state' => $this->state?->value,
        ];
    }

    /** @return array{allowed: bool, reason: string, state: string|null} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

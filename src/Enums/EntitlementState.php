<?php

namespace Goldnead\Entitlements\Enums;

/**
 * The six states a grant can be in.
 *
 * Five of them come from the codebase this was extracted out of. The sixth,
 * {@see self::Scheduled}, is the fix to a defect that shipped there: a grant
 * whose `starts_at` lies in the future was reported as `expired`, and that label
 * went straight to the account screen a customer reads. A pre-sale with a start
 * date told its buyer their access had run out.
 *
 * `Scheduled` and `Pending` are deliberately not the same state even though
 * neither grants access. `Pending` means "waiting for a confirmation that may
 * never come"; `Scheduled` means "everything is settled, the clock has not
 * reached it yet". Only one of the two turns into access on its own. Collapsing
 * them makes a pre-sale with a start date unrepresentable.
 */
enum EntitlementState: string
{
    /** Waiting on a confirmation (double opt-in, manual approval). Grants nothing. */
    case Pending = 'pending';

    /** Settled, but `starts_at` has not been reached. Grants nothing, becomes Active by itself. */
    case Scheduled = 'scheduled';

    case Active = 'active';

    /** Past `expires_at` but inside `grace_until`. Still grants access; that is the whole point. */
    case GracePeriod = 'grace_period';

    case Expired = 'expired';

    case Revoked = 'revoked';

    /**
     * Whether this state lets its subject through.
     *
     * Published as a named method rather than repeated as
     * `in_array($state, [Active, GracePeriod], true)` at every call site — which
     * is how the source codebase ended up with four copies of the same list and
     * one of them out of step.
     */
    public function grantsAccess(): bool
    {
        return $this === self::Active || $this === self::GracePeriod;
    }

    /**
     * Whether this state can still become Active without anyone doing anything.
     *
     * True for Scheduled (the clock does it) and false for everything else,
     * including Pending — which needs a confirmation, i.e. somebody doing
     * something.
     */
    public function isProvisional(): bool
    {
        return $this === self::Scheduled;
    }

    /**
     * Persistable statuses.
     *
     * `status` on the row is *not* the state. Three of the six states are
     * derived from timestamps and are never written: Scheduled, Expired and
     * GracePeriod-that-has-run-out are facts about the clock, and writing them
     * down would mean a row whose stored status silently goes stale. The stored
     * status carries only what the clock cannot tell you.
     *
     * @return list<string>
     */
    public static function storable(): array
    {
        return [
            self::Pending->value,
            self::Active->value,
            self::GracePeriod->value,
            self::Revoked->value,
        ];
    }

    /** @return array<string, string> handle => label, for a Select field or a filter. */
    public static function options(): array
    {
        return [
            self::Pending->value => __('entitlements::cp.state_pending'),
            self::Scheduled->value => __('entitlements::cp.state_scheduled'),
            self::Active->value => __('entitlements::cp.state_active'),
            self::GracePeriod->value => __('entitlements::cp.state_grace_period'),
            self::Expired->value => __('entitlements::cp.state_expired'),
            self::Revoked->value => __('entitlements::cp.state_revoked'),
        ];
    }

    public function label(): string
    {
        return self::options()[$this->value] ?? $this->value;
    }
}

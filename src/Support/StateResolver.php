<?php

namespace Goldnead\Entitlements\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Models\Entitlement;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that decides what state a grant is in.
 *
 * ## Why "one" is the requirement rather than "correct"
 *
 * The codebase this was extracted from resolved entitlement state in two places:
 * `EntitlementService::resolveState()` and `PackageAccessResolver::isActive()`.
 * They implemented the same rules independently, with the statuses spelled as
 * string literals in the second copy. When `pending` was introduced, only the
 * first copy learned about it — so a grant explicitly parked as "awaiting
 * confirmation" fell through the second copy's time checks and returned
 * `true`, granting access.
 *
 * The lesson is not "test both copies against each other". It is that the second
 * copy must not exist. Everything in this package — the manager, the access
 * decision, the Control Panel, the filters, the expiry scheduler — asks this
 * class.
 *
 * ## The resolution order
 *
 * 1. `revoked_at` set **or** `status = revoked`  -> Revoked
 * 2. `status = pending`                          -> Pending      (no access)
 * 3. `status = grace_period`                     -> GracePeriod while `grace_until` is in the
 *                                                   future, Expired once it is not
 * 4. `starts_at` in the future                   -> Scheduled    (no access)
 * 5. `expires_at` reached                        -> Expired
 * 6. otherwise                                   -> Active
 *
 * Step 1 answers on *either* signal deliberately. The source system displayed
 * `revoked_at` and then ignored it in every decision, and no write path ever set
 * it — so the two could not disagree, because one of them was never used. Here
 * both are real, and a row where they disagree must fail closed: a revocation
 * half-written by an interrupted process is still a revocation.
 *
 * Step 4 is the corrected defect. The source returned `Expired` for a grant that
 * had not started yet. The access effect was right (no access either way) but the
 * label went through `buildSummary()` onto the customer's own account screen: a
 * pre-sale told its buyer their access had run out.
 *
 * A `status` value this package does not know — the source's production data
 * contains an `admin` row nothing writes any more — is not special-cased. It
 * falls through to the time checks exactly as it did there, which means an
 * unknown status with an open window reads as Active. That is the pre-existing
 * behaviour and changing it silently would revoke access from real customers
 * during an upgrade.
 *
 * ## The SQL projection
 *
 * {@see self::constrain()} expresses the same six branches as a query
 * constraint, because a listing cannot resolve a hundred thousand rows in PHP to
 * filter them. It lives in this class, next to the definition, rather than in a
 * scope somewhere else — the split is what created the original defect.
 *
 * It is a projection, not a second opinion: `tests/Feature/StateResolverTest.php`
 * builds the full cartesian product of statuses and the four timestamps and
 * asserts, for every one of the six states, that the SQL selects exactly the rows
 * PHP resolves to it. The two cannot drift without the suite going red.
 */
class StateResolver
{
    /** Statuses that short-circuit before the time checks. */
    private const SHORT_CIRCUIT = [
        EntitlementState::Pending->value,
        EntitlementState::GracePeriod->value,
    ];

    public static function resolve(Entitlement $entitlement, ?DateTimeInterface $now = null): EntitlementState
    {
        $now = self::instant($now);
        $status = (string) $entitlement->status;

        // 1. Revocation, on either signal. See the class docblock on why both.
        if ($entitlement->revoked_at !== null || $status === EntitlementState::Revoked->value) {
            return EntitlementState::Revoked;
        }

        // 2. Pending must be handled explicitly. Falling through to the time
        //    checks below is precisely how the second copy in the source system
        //    handed out access to unconfirmed opt-ins.
        if ($status === EntitlementState::Pending->value) {
            return EntitlementState::Pending;
        }

        // 3. An explicit grace period outlives expires_at, which is why it is
        //    checked before the window at all. Without grace_until it is over.
        if ($status === EntitlementState::GracePeriod->value) {
            return $entitlement->grace_until !== null && $entitlement->grace_until->greaterThan($now)
                ? EntitlementState::GracePeriod
                : EntitlementState::Expired;
        }

        // 4. The fix: not started is not the same as finished.
        if ($entitlement->starts_at !== null && $entitlement->starts_at->greaterThan($now)) {
            return EntitlementState::Scheduled;
        }

        // 5. `<=` rather than `<`: the instant of expiry is already outside.
        if ($entitlement->expires_at !== null && $entitlement->expires_at->lessThanOrEqualTo($now)) {
            return EntitlementState::Expired;
        }

        return EntitlementState::Active;
    }

    /**
     * Constrains a query to the rows that resolve to `$state`.
     *
     * @param  Builder<Entitlement>  $query
     * @return Builder<Entitlement>
     */
    public static function constrain(Builder $query, EntitlementState $state, ?DateTimeInterface $now = null): Builder
    {
        $at = self::instant($now)->format('Y-m-d H:i:s');

        return match ($state) {
            EntitlementState::Revoked => $query->where(
                fn ($q) => $q->whereNotNull('revoked_at')->orWhere('status', EntitlementState::Revoked->value)
            ),

            EntitlementState::Pending => self::live($query)
                ->where('status', EntitlementState::Pending->value),

            EntitlementState::GracePeriod => self::live($query)
                ->where('status', EntitlementState::GracePeriod->value)
                ->where('grace_until', '>', $at),

            // Two ways to be expired: a grace period that has run out, or a
            // window whose end has passed. Mirrors branches 3 and 5.
            EntitlementState::Expired => self::live($query)->where(function ($q) use ($at) {
                $q->where(fn ($inner) => $inner
                    ->where('status', EntitlementState::GracePeriod->value)
                    ->where(fn ($grace) => $grace->whereNull('grace_until')->orWhere('grace_until', '<=', $at))
                )->orWhere(fn ($inner) => self::afterStart($inner, $at)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', $at)
                );
            }),

            EntitlementState::Scheduled => self::live($query)
                ->whereNotIn('status', self::SHORT_CIRCUIT)
                ->whereNotNull('starts_at')
                ->where('starts_at', '>', $at),

            EntitlementState::Active => self::live($query)
                ->where(fn ($q) => self::afterStart($q, $at))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $at)),
        };
    }

    /**
     * Constrains a query to the rows that grant access right now.
     *
     * Not `constrain(Active)->orWhere(constrain(GracePeriod))`: the OR of two
     * already-composed constraints is easy to get subtly wrong with grouping,
     * and this is the query that decides whether a customer gets in.
     *
     * @param  Builder<Entitlement>  $query
     * @return Builder<Entitlement>
     */
    public static function constrainToAccess(Builder $query, ?DateTimeInterface $now = null): Builder
    {
        $at = self::instant($now)->format('Y-m-d H:i:s');

        return self::live($query)->where(function ($q) use ($at) {
            $q->where(fn ($active) => self::afterStart($active, $at)
                ->where(fn ($window) => $window->whereNull('expires_at')->orWhere('expires_at', '>', $at))
            )->orWhere(fn ($grace) => $grace
                ->where('status', EntitlementState::GracePeriod->value)
                ->where('grace_until', '>', $at)
            );
        });
    }

    /** Branch 1, inverted: everything the later branches may look at is not revoked. */
    /**
     * @param  Builder<Entitlement>  $query
     * @return Builder<Entitlement>
     */
    private static function live(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where('status', '!=', EntitlementState::Revoked->value);
    }

    /**
     * Branches 2 and 4 combined for the branches that come after them: neither
     * short-circuiting status applies, and the start has been reached.
     */
    /**
     * @param  Builder<Entitlement>  $query
     * @return Builder<Entitlement>
     */
    private static function afterStart(Builder $query, string $at): Builder
    {
        return $query
            ->whereNotIn('status', self::SHORT_CIRCUIT)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at));
    }

    private static function instant(?DateTimeInterface $now): CarbonImmutable
    {
        return $now !== null
            ? CarbonImmutable::instance($now)->utc()
            : CarbonImmutable::now('UTC');
    }
}

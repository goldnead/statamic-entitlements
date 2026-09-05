<?php

namespace Goldnead\Entitlements\Query\Scopes\Filters;

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Support\StateResolver;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Filters by resolved state, not by the `status` column.
 *
 * Those are different questions and the difference is the whole point of this
 * package. Three of the six states — Scheduled, Expired, and a grace period that
 * has run out — exist only as a relationship between the row's dates and the
 * current time; no column holds them. A filter built on `WHERE status = ?` would
 * offer "Expired" and return nothing, because nothing is ever stored as expired.
 *
 * The SQL comes from {@see StateResolver}, the same class that answers the
 * question in PHP. This filter contains no rules of its own — the moment it did,
 * this package would have the two competing state implementations it exists to
 * remove.
 */
class State extends EntitlementFilter
{
    protected static $handle = 'entitlements_state';

    protected $pinned = true;

    public static function title()
    {
        return __('entitlements::cp.filter_state');
    }

    public function fieldItems()
    {
        return [
            'state' => [
                'display' => __('entitlements::cp.filter_state'),
                'type' => 'select',
                'clearable' => true,
                'placeholder' => __('entitlements::cp.filter_any'),
                'options' => EntitlementState::options(),
            ],
        ];
    }

    public function apply($query, $values)
    {
        $state = EntitlementState::tryFrom((string) ($values['state'] ?? ''));

        if (! $state) {
            return;
        }

        // `Scope::apply()` documents its first argument as
        // `Statamic\Query\Builder`, because that is what a filter over entries,
        // assets or users receives. This one is never offered to those listings
        // — {@see EntitlementFilter::visibleTo()} pins it to the entitlements
        // listing, and {@see EntitlementController::listing()} builds that with
        // `Entitlement::query()`. The parameter type cannot be narrowed on an
        // override without breaking the contract in the other direction, so the
        // invariant is asserted here instead: loudly, because a filter that
        // silently returned everything would read as "no rows match" on screen.
        if (! $query instanceof Builder) {
            throw new InvalidArgumentException(
                'The state filter resolves against the entitlements table and needs an Eloquent builder, got '.get_debug_type($query).'.'
            );
        }

        StateResolver::constrain($query, $state);
    }

    public function badge($values)
    {
        $state = EntitlementState::tryFrom((string) ($values['state'] ?? ''));

        return __('entitlements::cp.filter_state').': '.($state?->label() ?? '');
    }
}

<?php

namespace Goldnead\Entitlements\Query\Scopes\Filters;

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Support\StateResolver;

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

        StateResolver::constrain($query, $state);
    }

    public function badge($values)
    {
        $state = EntitlementState::tryFrom((string) ($values['state'] ?? ''));

        return __('entitlements::cp.filter_state').': '.($state?->label() ?? '');
    }
}

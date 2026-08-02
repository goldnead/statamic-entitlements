<?php

namespace Goldnead\Entitlements\Query\Scopes\Filters;

use Statamic\Query\Scopes\Filter;

/**
 * Statamic registers scopes globally and `Filter::visibleTo()` defaults to
 * `true`, so a filter that does not answer that question turns up on the
 * Entries, Assets and Users listings as well. Everything in this folder is
 * scoped to the one listing it was written for.
 *
 * The listing key is also the contract between `Scope::filters()` on the page
 * and `queryFilters()` in the controller; naming it once is what stops the two
 * sides drifting into a filter panel that renders and filters nothing.
 */
abstract class EntitlementFilter extends Filter
{
    public const LISTING_KEY = 'entitlements-listing';

    public function visibleTo($key)
    {
        return $key === self::LISTING_KEY;
    }
}

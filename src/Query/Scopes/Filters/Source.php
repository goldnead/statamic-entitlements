<?php

namespace Goldnead\Entitlements\Query\Scopes\Filters;

use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\SourceRegistry;

/**
 * The options are the registry plus whatever the data actually contains.
 *
 * A dropdown built from config alone would hide every grant written by a source
 * nobody remembered to register — which is precisely the grant somebody is
 * looking for when they open this filter.
 */
class Source extends EntitlementFilter
{
    protected static $handle = 'entitlements_source';

    protected $pinned = true;

    public static function title()
    {
        return __('entitlements::cp.filter_source');
    }

    public function fieldItems()
    {
        return [
            'source' => [
                'display' => __('entitlements::cp.filter_source'),
                'type' => 'select',
                'clearable' => true,
                'placeholder' => __('entitlements::cp.filter_any'),
                'options' => app(SourceRegistry::class)->optionsIncluding(
                    Entitlement::query()->distinct()->pluck('source')->all()
                ),
            ],
        ];
    }

    public function apply($query, $values)
    {
        if (! $value = $values['source'] ?? null) {
            return;
        }

        $query->where('source', $value);
    }

    public function badge($values)
    {
        return __('entitlements::cp.filter_source').': '.($values['source'] ?? '');
    }
}

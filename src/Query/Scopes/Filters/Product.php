<?php

namespace Goldnead\Entitlements\Query\Scopes\Filters;

/**
 * A text filter rather than a select.
 *
 * `product_slug` is a free string with no catalogue behind it — that is what
 * makes this package independent of what a product is — so there is no list to
 * offer. An exact match rather than a LIKE: the slug is an identifier, and a
 * substring search over it belongs in the listing's own search box, which
 * already does that.
 */
class Product extends EntitlementFilter
{
    protected static $handle = 'entitlements_product';

    public static function title()
    {
        return __('entitlements::cp.filter_product');
    }

    public function fieldItems()
    {
        return [
            'product_slug' => [
                'display' => __('entitlements::cp.filter_product'),
                'type' => 'text',
                'placeholder' => __('entitlements::cp.filter_product_placeholder'),
            ],
        ];
    }

    public function apply($query, $values)
    {
        $slug = trim((string) ($values['product_slug'] ?? ''));

        if ($slug === '') {
            return;
        }

        $query->where('product_slug', $slug);
    }

    public function badge($values)
    {
        return __('entitlements::cp.filter_product').': '.($values['product_slug'] ?? '');
    }
}

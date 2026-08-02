<?php

namespace Goldnead\Entitlements\Contracts;

use Goldnead\Entitlements\EntitlementManager;
use Goldnead\Entitlements\Support\NullPackageResolver;

/**
 * Extension point: expanding a bundle into the products it contains.
 *
 * This package deliberately has no idea what a product or a package is. A grant
 * carries a `product_slug` and nothing else — no foreign key, no catalogue, no
 * table. That is what makes it extractable at all: in the source system,
 * products and bundles live in Statamic collections (`member_products`,
 * `member_packages`), and any model of them here would have been a second,
 * competing catalogue.
 *
 * But access questions are asked about products, while purchases are often made
 * of bundles. Bridging that is the consumer's job, and this is where they do it:
 * given the slug being asked about, return the bundle slugs that would also
 * grant it. {@see EntitlementManager::decide()} then treats
 * a grant for any of them as a grant for the product.
 *
 * The default {@see NullPackageResolver} returns
 * nothing, so an install that never binds one behaves exactly as if bundles did
 * not exist.
 *
 * Implementations must be cheap and must not query per row — this is called on
 * the access path.
 */
interface PackageResolver
{
    /**
     * Slugs whose grant also covers `$productSlug`, excluding the slug itself.
     *
     * @return list<string>
     */
    public function packagesContaining(string $productSlug): array;
}

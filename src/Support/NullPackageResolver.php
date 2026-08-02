<?php

namespace Goldnead\Entitlements\Support;

use Goldnead\Entitlements\Contracts\PackageResolver;

/**
 * The default: there are no bundles.
 *
 * A null object rather than a nullable dependency, so the access path has one
 * shape instead of two and nothing has to remember the `?->` .
 */
class NullPackageResolver implements PackageResolver
{
    /** @return list<string> */
    public function packagesContaining(string $productSlug): array
    {
        return [];
    }
}

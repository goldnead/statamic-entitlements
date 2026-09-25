<?php

use Statamic\Facades\CP\Nav;

/**
 * The nav closure runs only when a CP page builds the sidebar, which no other
 * test here does. A call to a NavItem method that does not exist took the
 * whole playground CP down on 25.09.2026 while every test was green.
 */
it('builds the navigation with the three entries', function () {
    $user = cpUserWith(['access cp', 'view entitlements']);
    $this->actingAs($user);

    $built = collect(Nav::build())->flatMap(fn ($section) => $section['items'] ?? []);
    $ours = $built->first(fn ($item) => $item->display() === __('entitlements::cp.nav'));

    expect($ours)->not->toBeNull()
        ->and(collect($ours->resolveChildren()->children())->map->display()->all())->toBe([
            __('entitlements::cp.nav_grants'),
            __('entitlements::cp.limits_title'),
            __('entitlements::cp.wiring_title'),
        ]);
});

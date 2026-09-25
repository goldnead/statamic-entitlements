<?php

use Goldnead\Entitlements\Facades\Entitlements;
use Illuminate\Http\Request;
use Statamic\CP\Breadcrumbs\Breadcrumbs;
use Statamic\Facades\CP\Nav;

/**
 * The nav closure runs only when a CP page builds the sidebar, which no other
 * test here does. A call to a NavItem method that does not exist took the
 * whole playground CP down on 25.09.2026 while every test was green.
 */
it('builds the navigation with the three sub-pages, grants last', function () {
    $user = cpUserWith(['access cp', 'view entitlements']);
    $this->actingAs($user);

    $built = collect(Nav::build())->flatMap(fn ($section) => $section['items'] ?? []);
    $ours = $built->first(fn ($item) => $item->display() === __('entitlements::cp.nav'));

    expect($ours)->not->toBeNull()
        ->and(collect($ours->resolveChildren()->children())->map->display()->all())->toBe([
            __('entitlements::cp.limits_title'),
            __('entitlements::cp.wiring_title'),
            __('entitlements::cp.nav_grants'),
        ]);
});

it('keeps a breadcrumb on a grant\'s own page', function () {
    $this->actingAs(cpUserWith(['access cp', 'view entitlements']));
    $this->app->instance('request', Request::create(cp_route('entitlements.show', ['entitlement' => 5])));

    $crumbs = collect(Breadcrumbs::build())->flatten()->map(fn ($c) => $c->text())->all();

    expect($crumbs)->toContain(__('entitlements::cp.nav_grants'));
});

it('puts the limits page, not the grants listing, into the breadcrumb of a limits form', function () {
    config()->set('statamic.editions.pro', true);
    Entitlements::setLimits('chor', ['analyses' => 5]);
    $user = cpUserWith(['access cp', 'view entitlements', 'manage entitlements limits']);

    $this->actingAs($user);

    // The breadcrumb is built from the current request's URL.
    $this->app->instance('request', Request::create(cp_route('entitlements.limits.edit', ['product' => 'chor'])));

    $crumbs = collect(Breadcrumbs::build())->flatten()->map(fn ($c) => $c->text())->all();

    expect($crumbs)->toContain(__('entitlements::cp.limits_title'))
        ->and($crumbs)->not->toContain(__('entitlements::cp.nav_grants'));
});

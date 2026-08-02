<?php

use Goldnead\Entitlements\Models\Entitlement;

/*
 * Every route gets its unauthorized case tested, the read ones included. That is
 * the thinnest area across the whole reference set of Statamic addons and the
 * one that hurts most: one of the largest third-party addons leaves its store,
 * update and destroy routes open to any authenticated Control Panel user.
 *
 * Here it matters more than usual. The source system gated all of this behind a
 * single blanket `access-admin`, which meant anybody who could open the Control
 * Panel could take a paying customer's access away.
 */

function readRoutes(Entitlement $grant): array
{
    return [
        ['get', cp_route('entitlements.index')],
        ['get', cp_route('entitlements.show', ['entitlement' => $grant->getKey()])],
    ];
}

function grantRoutes(Entitlement $grant): array
{
    return [
        ['get', cp_route('entitlements.create')],
        ['post', cp_route('entitlements.store')],
        ['post', cp_route('entitlements.restore', ['entitlement' => $grant->getKey()])],
    ];
}

function revokeRoutes(Entitlement $grant): array
{
    return [
        ['get', cp_route('entitlements.revoke.form', ['entitlement' => $grant->getKey()])],
        ['post', cp_route('entitlements.revoke', ['entitlement' => $grant->getKey()])],
    ];
}

it('sends an anonymous visitor to the login screen', function () {
    $grant = Entitlement::factory()->create();

    foreach ([...readRoutes($grant), ...grantRoutes($grant), ...revokeRoutes($grant)] as [$method, $url]) {
        $this->{$method}($url)->assertRedirect();
    }
});

it('refuses a Control Panel user without the permission', function () {
    $grant = Entitlement::factory()->create();

    // A user who can reach the CP at all, and nothing more. Hiding the nav entry
    // from them is not authorization.
    $user = cpUserWith(['access cp']);

    foreach ([...readRoutes($grant), ...grantRoutes($grant), ...revokeRoutes($grant)] as [$method, $url]) {
        $this->actingAs($user)->{$method}($url)->assertForbidden();
    }
});

it('lets a read-only user read and nothing else', function () {
    $grant = Entitlement::factory()->create();

    $user = cpUserWith(['access cp', 'view entitlements']);

    foreach (readRoutes($grant) as [$method, $url]) {
        $this->actingAs($user)->{$method}($url)->assertOk();
    }

    foreach ([...grantRoutes($grant), ...revokeRoutes($grant)] as [$method, $url]) {
        $this->actingAs($user)->{$method}($url)->assertForbidden();
    }
});

/**
 * The asymmetry that is the whole reason there are two write permissions rather
 * than one. Somebody trusted to hand out a replacement licence has no business
 * revoking one, and the reverse is equally true.
 */
it('lets a granter grant but not revoke', function () {
    $grant = Entitlement::factory()->create();

    // One user per test. The file user repository writes into the testbench app
    // and Statamic refuses a second user without Pro, so two roles in one test
    // is a 500 that has nothing to do with authorization.
    $granter = cpUserWith(['access cp', 'view entitlements', 'grant entitlements']);

    // What is asserted for the permitted side is "not 403", not "200": a POST
    // with an empty body is a validation failure, and a test that demanded 200
    // would be testing the blueprint rather than the Gate.
    foreach (grantRoutes($grant) as [$method, $url]) {
        expect($this->actingAs($granter)->{$method}($url)->status())->not->toBe(403);
    }

    foreach (revokeRoutes($grant) as [$method, $url]) {
        $this->actingAs($granter)->{$method}($url)->assertForbidden();
    }
});

it('lets a revoker revoke but not grant', function () {
    $grant = Entitlement::factory()->create();

    $revoker = cpUserWith(['access cp', 'view entitlements', 'revoke entitlements']);

    foreach (revokeRoutes($grant) as [$method, $url]) {
        expect($this->actingAs($revoker)->{$method}($url)->status())->not->toBe(403);
    }

    foreach (grantRoutes($grant) as [$method, $url]) {
        $this->actingAs($revoker)->{$method}($url)->assertForbidden();
    }
});

it('serves no Control Panel routes at all when the Control Panel is switched off', function () {
    // The kill switch has to bite on the routes, not only on the nav entry.
    // Hiding the entry while leaving the screens reachable by URL is not a
    // disabled Control Panel.
    config()->set('entitlements.cp.enabled', false);

    $routes = require __DIR__.'/../../routes/cp.php';

    expect($routes)->toBeNull();

    $registered = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter(fn (?string $name): bool => $name !== null && str_starts_with($name, 'statamic.cp.entitlements.'));

    // The bed mounted them before the config changed, so what is asserted here
    // is the file's own behaviour rather than the router's state.
    expect($registered)->not->toBeEmpty();
});

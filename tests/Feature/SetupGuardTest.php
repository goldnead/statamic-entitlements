<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * A composer install puts the addon and its nav entry in place; the migrations
 * are a separate, manual step. Between the two, `/cp/entitlements` answered HTTP
 * 500 — the listing reaches for the `entitlements` table that is not there yet.
 * These tests reproduce that database and hold the page to an empty state plus
 * a line in the log.
 */

function dropEntitlementTables(): void
{
    Schema::dropIfExists('entitlements');
}

it('answers 200 on the index when its table is missing', function () {
    dropEntitlementTables();

    $this->actingAs(cpUserWith(['access cp', 'view entitlements']))
        ->get(cp_route('entitlements.index'))
        ->assertOk();
});

it('renders the setup screen and names the missing table', function () {
    dropEntitlementTables();

    $response = $this->actingAs(cpUserWith(['access cp', 'view entitlements']))
        ->get(cp_route('entitlements.index'))
        ->assertOk();

    $page = $response->viewData('page');

    expect($page['component'])->toBe('entitlements::SetupRequired')
        ->and($page['props']['tables'])->toContain('entitlements')
        ->and($page['props']['heading'])->not->toBeEmpty()
        ->and($page['props']['description'])->not->toBeEmpty();
});

/*
 * The point of the guard is a readable page, not a quiet one. If this test ever
 * goes red the addon has traded a visible 500 for a silent nothing.
 */
it('writes the reason to the log', function () {
    dropEntitlementTables();

    Log::spy();

    $this->actingAs(cpUserWith(['access cp', 'view entitlements']))
        ->get(cp_route('entitlements.index'))
        ->assertOk();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'entitlements')
            && str_contains($message, 'php artisan migrate'))
        ->once();
});

/*
 * The listing fetches its rows over XHR against this same action. Guarding only
 * the Inertia branch would leave that request answering 500 behind a page that
 * looked fine.
 */
it('guards the listing xhr as well', function () {
    dropEntitlementTables();

    $this->actingAs(cpUserWith(['access cp', 'view entitlements']))
        ->getJson(cp_route('entitlements.index'))
        ->assertOk();
});

it('still renders the listing on a migrated install', function () {
    $response = $this->actingAs(cpUserWith(['access cp', 'view entitlements']))
        ->get(cp_route('entitlements.index'))
        ->assertOk();

    expect($response->viewData('page')['component'])->toBe('entitlements::Entitlements/Index');
});

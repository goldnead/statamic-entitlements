<?php

use Goldnead\Entitlements\Bridges\ActivityBridge;
use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Events\EntitlementGranted;
use Goldnead\Entitlements\Events\EntitlementRevoked;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\ServiceProvider;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\Event;

/**
 * The proof that every optional coupling really is optional.
 *
 * Acceptance criterion 10 of the extraction rule: with no sibling addon
 * installed, the package must still write a grant, resolve its state, decide
 * access, fire its events and serve the Control Panel. Nothing here is allowed
 * to depend on activity, leadhub, lead-magnets or automations.
 *
 * The bridge is disabled in config rather than by unloading the stand-in facade,
 * because the stand-in is loaded once for the whole run (see tests/Pest.php) and
 * a test that could unload it would make every other test's result depend on
 * file order. Switching the bridge off reaches the same branch: `attach()`
 * returns before it looks for the sibling at all.
 */
beforeEach(function () {
    config()->set('entitlements.bridges.activity', false);
    ActivityBridge::forget();

    $this->subject = new SubjectReference('user', '42');
});

it('attaches no bridge at all', function () {
    app()->getProvider(ServiceProvider::class)->bootAddon();

    expect(ActivityBridge::isAttached())->toBeFalse();
});

it('writes a grant, resolves it, decides access and fires its events with nothing else installed', function () {
    Event::fake([EntitlementGranted::class, EntitlementRevoked::class]);

    $grant = Entitlements::grant($this->subject, 'course-a', 'mollie', 'tr_1');

    expect($grant->state())->toBe(EntitlementState::Active)
        ->and(Entitlements::allows($this->subject, 'course-a'))->toBeTrue()
        ->and(Entitlements::activeProductSlugsFor($this->subject))->toBe(['course-a']);

    Event::assertDispatched(EntitlementGranted::class);

    expect(Entitlements::revoke($grant, 'Refunded'))->toBeTrue()
        ->and(Entitlements::allows($this->subject, 'course-a'))->toBeFalse();

    Event::assertDispatched(EntitlementRevoked::class);
});

it('runs the scheduled announcement pass with nothing else installed', function () {
    Entitlements::grant($this->subject, 'course-a', 'manual', 'x', expiresAt: now()->addDay());

    $this->travelTo(now()->addWeek());

    $this->artisan('entitlements:announce')->assertSuccessful();

    expect(Entitlement::query()->first()->announced_state)->toBe(EntitlementState::Expired->value);
});

it('serves the Control Panel listing with nothing else installed', function () {
    Entitlements::grant($this->subject, 'course-a', 'manual');

    $this->actingAs(cpUserWith(['access cp', 'view entitlements']))
        ->get(cp_route('entitlements.index'))
        ->assertOk();
});

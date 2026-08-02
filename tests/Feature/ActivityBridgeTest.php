<?php

use Goldnead\Activity\Facades\Activity;
use Goldnead\Entitlements\Bridges\ActivityBridge;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\ServiceProvider;
use Goldnead\Entitlements\Support\SubjectReference;

beforeEach(function () {
    Activity::forget();
    ActivityBridge::forget();

    $this->subject = new SubjectReference('user', '42');
});

it('does not attach when the sibling is switched off in config', function () {
    config()->set('entitlements.bridges.activity', false);

    expect(ActivityBridge::attach(app()))->toBeFalse()
        ->and(ActivityBridge::isAttached())->toBeFalse();
});

it('attaches once and stays attached', function () {
    expect(ActivityBridge::attach(app()))->toBeTrue()
        ->and(ActivityBridge::attach(app()))->toBeTrue()
        ->and(ActivityBridge::isAttached())->toBeTrue();
});

it('records a grant, a pending grant, a revocation and an expiry', function () {
    app()->getProvider(ServiceProvider::class)->bootAddon();

    $grant = Entitlements::grant($this->subject, 'course-a', 'mollie', 'tr_1');
    Entitlements::revoke($grant, 'Refunded');

    Entitlements::grantPending($this->subject, 'lead-magnet', 'newsletter_optin', 'lead-magnet');

    $expiring = Entitlements::grant($this->subject, 'course-b', 'manual', 'x', expiresAt: now()->addDay());
    $this->travelTo(now()->addWeek());
    $this->artisan('entitlements:announce')->assertSuccessful();

    $types = array_column(Activity::$recorded, 'type');

    expect($types)->toContain('entitlements.granted')
        ->toContain('entitlements.revoked')
        ->toContain('entitlements.pending')
        ->toContain('entitlements.expired');

    $granted = collect(Activity::$recorded)->firstWhere('type', 'entitlements.granted');

    expect($granted['attributes']['brand_id'])->toBe($grant->brand_id)
        ->and($granted['attributes']['dedupe_key'])->toBe('entitlements.granted:'.$grant->getKey().':new')
        ->and($granted['attributes']['properties']['product_slug'])->toBe('course-a')
        ->and($granted['attributes']['properties']['source_ref'])->toBe('tr_1');

    $revoked = collect(Activity::$recorded)->firstWhere('type', 'entitlements.revoked');

    expect($revoked['attributes']['properties']['reason'])->toBe('Refunded');
});

it('distinguishes a grant that came from a confirmation from one written outright', function () {
    app()->getProvider(ServiceProvider::class)->bootAddon();

    $pending = Entitlements::grantPending($this->subject, 'lead-magnet', 'newsletter_optin', 'lead-magnet');
    Entitlements::claimPending($pending);

    $keys = collect(Activity::$recorded)
        ->where('type', 'entitlements.granted')
        ->pluck('attributes.dedupe_key')
        ->all();

    expect($keys)->toBe(['entitlements.granted:'.$pending->getKey().':pending']);
});

/**
 * A failing ledger must not fail the write that produced the fact. Granting
 * access after a successful payment has to succeed even when the optional addon
 * next door is misconfigured.
 */
it('lets a grant succeed when the ledger throws', function () {
    app()->getProvider(ServiceProvider::class)->bootAddon();

    Activity::$throws = new RuntimeException('ledger is down');

    $grant = Entitlements::grant($this->subject, 'course-a', 'mollie', 'tr_1');

    expect($grant->exists)->toBeTrue()
        ->and($grant->grantsAccess())->toBeTrue();
});

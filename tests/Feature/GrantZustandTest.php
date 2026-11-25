<?php

use Carbon\CarbonImmutable;
use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\SubjectReference;

/**
 * Zustandsübergänge eines Grants, die über Entzug, Wiederherstellung und
 * Gnadenfrist hinausgehen: was mit einem widerrufenen Grant passiert, was ein
 * halb geschriebener Entzug bedeutet und wie der Grund gespeichert wird.
 */
beforeEach(function () {
    $this->subject = new SubjectReference('user', '42');
});

it('does not put a revoked grant into a grace period, and it stays revoked', function () {
    $grant = Entitlements::grant($this->subject, 'course-a', 'manual');
    Entitlements::revoke($grant, 'Refunded');

    $grant->refresh();

    expect(Entitlements::enterGracePeriod($grant, CarbonImmutable::now()->addWeek()))->toBeFalse()
        ->and($grant->fresh()->state())->toBe(EntitlementState::Revoked)
        ->and($grant->fresh()->grantsAccess())->toBeFalse();
});

it('reads a half-written revocation as revoked and restores it', function () {
    $grant = Entitlements::grant($this->subject, 'course-a', 'manual');

    // Nur der Status ist geschrieben, revoked_at fehlt: ein unterbrochener Entzug.
    Entitlement::query()
        ->whereKey($grant->getKey())
        ->update(['status' => EntitlementState::Revoked->value]);

    expect($grant->fresh()->revoked_at)->toBeNull()
        ->and($grant->fresh()->state())->toBe(EntitlementState::Revoked)
        ->and($grant->fresh()->grantsAccess())->toBeFalse();

    expect(Entitlements::restore($grant->fresh()))->toBeTrue()
        ->and($grant->fresh()->state())->toBe(EntitlementState::Active)
        ->and($grant->fresh()->grantsAccess())->toBeTrue();
});

it('stores a revocation reason without the spaces around it', function () {
    $grant = Entitlements::grant($this->subject, 'course-a', 'manual');

    expect(Entitlements::revoke($grant, "  Refunded \n"))->toBeTrue();

    expect($grant->fresh()->revoked_reason)->toBe('Refunded');
});

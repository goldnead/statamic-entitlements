<?php

use Carbon\CarbonImmutable;
use Goldnead\Entitlements\Http\Controllers\Cp\EntitlementController;

/**
 * A grant without an expiry date shows an empty cell, not the word " UTC".
 *
 * `$date?->format(...).' UTC'` looks right and is not: the nullsafe operator
 * short-circuits only the call, so a null date still concatenates and the
 * column read " UTC". The detail panel filtered that string back out
 * afterwards; the listing did not, and printed it at the reader.
 */
it('gives nothing back for a date that is not set', function () {
    $stempel = new ReflectionMethod(EntitlementController::class, 'stamp');
    $stempel->setAccessible(true);

    $controller = app(EntitlementController::class);

    expect($stempel->invoke($controller, null))->toBeNull();
});

it('still stamps a date that is set', function () {
    $stempel = new ReflectionMethod(EntitlementController::class, 'stamp');
    $stempel->setAccessible(true);

    $wert = $stempel->invoke(
        app(EntitlementController::class),
        CarbonImmutable::parse('2026-08-25 12:00', 'UTC'),
    );

    expect($wert)->toBe('2026-08-25 12:00 UTC');
});

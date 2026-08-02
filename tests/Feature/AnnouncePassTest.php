<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Events\EntitlementExpired;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\Event;

/**
 * The scheduled pass itself: its options, and the failure mode its own base
 * trait exists to prevent.
 */
beforeEach(function () {
    $this->subject = new SubjectReference('user', '42');
});

it('stops after the limit and picks the rest up on the next run', function () {
    Event::fake([EntitlementExpired::class]);

    foreach (range(1, 5) as $index) {
        Entitlements::grant($this->subject, 'course-'.$index, 'manual', 'ref-'.$index, expiresAt: now()->addDay());
    }

    $this->travelTo(now()->addWeek());

    $this->artisan('entitlements:announce', ['--limit' => 2])->assertSuccessful();

    // A first run against a table that has been accumulating expired grants for a
    // year must not dispatch tens of thousands of events inside one command.
    Event::assertDispatchedTimes(EntitlementExpired::class, 2);

    $this->artisan('entitlements:announce')->assertSuccessful();

    Event::assertDispatchedTimes(EntitlementExpired::class, 5);
});

/**
 * The documented failure mode of `RunsForEachBrand`, and the reason the pass
 * uses it: a console run has no session and therefore no current brand, so with
 * multi-brand on, the global scope fails closed and every query returns nothing.
 * The command then reports success, announces nothing, and looks perfectly
 * healthy — for as long as nobody checks.
 */
it('announces in every brand rather than silently doing nothing', function () {
    Event::fake([EntitlementExpired::class]);

    $this->enableMultiBrand();

    $alpha = $this->makeBrand('alpha');
    $beta = $this->makeBrand('beta');

    foreach ([$alpha, $beta] as $brand) {
        app('brand-context')->runFor($brand, fn () => Entitlements::grant(
            $this->subject, 'course-a', 'manual', 'ref', expiresAt: now()->addDay(),
        ));
    }

    app('brand-context')->forget();

    $this->travelTo(now()->addWeek());

    $this->artisan('entitlements:announce')->assertSuccessful();

    Event::assertDispatchedTimes(EntitlementExpired::class, 2);
});

it('can be restricted to one brand', function () {
    Event::fake([EntitlementExpired::class]);

    $this->enableMultiBrand();

    $alpha = $this->makeBrand('alpha');
    $beta = $this->makeBrand('beta');

    foreach ([$alpha, $beta] as $brand) {
        app('brand-context')->runFor($brand, fn () => Entitlements::grant(
            $this->subject, 'course-a', 'manual', 'ref', expiresAt: now()->addDay(),
        ));
    }

    app('brand-context')->forget();

    $this->travelTo(now()->addWeek());

    $this->artisan('entitlements:announce', ['--brand' => 'alpha'])->assertSuccessful();

    Event::assertDispatchedTimes(EntitlementExpired::class, 1);
});

it('keeps consumer metadata on the grant, without promising its key order', function () {
    $grant = Entitlements::grant($this->subject, 'course-a', 'mollie', 'tr_1', meta: [
        'order_total' => 4900,
        'currency' => 'EUR',
    ]);

    // Asserted key by key rather than as a whole array, and that is a real
    // engine difference rather than fussiness: MySQL's native JSON column
    // normalises and reorders object keys on the way in, SQLite stores the text
    // verbatim. The MySQL leg of this suite caught it. `meta` is a bag for the
    // consumer, and this package does not promise the order it comes back in.
    $meta = $grant->fresh()->meta;

    expect($meta)->toHaveCount(2)
        ->and($meta['order_total'])->toBe(4900)
        ->and($meta['currency'])->toBe('EUR');
});

it('marks a grant that became active by the clock as announced, so it is announced once', function () {
    $grant = Entitlements::grant($this->subject, 'course-a', 'manual', startsAt: now()->addDay());

    expect($grant->announced_state)->toBeNull();

    $this->travelTo(now()->addWeek());
    $this->artisan('entitlements:announce')->assertSuccessful();

    expect($grant->fresh()->announced_state)->toBe(EntitlementState::Active->value)
        ->and(Entitlement::query()->first()->state())->toBe(EntitlementState::Active);
});

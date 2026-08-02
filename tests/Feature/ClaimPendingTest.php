<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Events\EntitlementGranted;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\Event;

/**
 * The confirm-first path, and the one piece of the source system that needed no
 * correction.
 *
 * A lead magnet is claimed, the grant is parked as Pending, and nothing is
 * handed over until the double opt-in comes back. When it does, the flip to
 * Active has to happen exactly once even if the confirmation arrives twice — a
 * queue retry, a double-clicked link, two near-simultaneous webhook deliveries.
 *
 * The mechanism is a conditional `WHERE status = pending` UPDATE and its
 * affected-row count. The database serialises the racing writes, so exactly one
 * caller sees 1 and every other sees 0. Everything the consumer does once — send
 * the download, issue the link — hangs off that boolean.
 */
beforeEach(function () {
    $this->contact = new SubjectReference('contact', '9f1c-uuid');
});

it('grants access when the confirmation lands', function () {
    $grant = Entitlements::grantPending($this->contact, 'warmup-guide', 'newsletter_optin', 'warmup-guide');

    expect(Entitlements::allows($this->contact, 'warmup-guide'))->toBeFalse();

    expect(Entitlements::claimPending($grant))->toBeTrue();

    expect($grant->fresh()->state())->toBe(EntitlementState::Active)
        ->and(Entitlements::allows($this->contact, 'warmup-guide'))->toBeTrue();
});

it('lets exactly one of two simultaneous confirmations win', function () {
    Event::fake([EntitlementGranted::class]);

    $grant = Entitlements::grantPending($this->contact, 'warmup-guide', 'newsletter_optin', 'warmup-guide');

    // Two processes, each holding its own instance of the row it has just read
    // as pending. Both are about to deliver.
    $first = Entitlement::query()->findOrFail($grant->getKey());
    $second = Entitlement::query()->findOrFail($grant->getKey());

    $firstWon = Entitlements::claimPending($first);
    $secondWon = Entitlements::claimPending($second);

    expect($firstWon)->toBeTrue()
        ->and($secondWon)->toBeFalse();

    // And therefore exactly one delivery. This is what "exactly once" means in
    // practice: not that the flip is atomic, but that only one caller is told it
    // may act.
    Event::assertDispatchedTimes(EntitlementGranted::class, 1);
});

it('reports the transition it came from, so a listener can tell a confirmation from a purchase', function () {
    Event::fake([EntitlementGranted::class]);

    $grant = Entitlements::grantPending($this->contact, 'warmup-guide', 'newsletter_optin', 'warmup-guide');
    Entitlements::claimPending($grant);

    Event::assertDispatched(EntitlementGranted::class, fn (EntitlementGranted $e): bool => $e->previousState === EntitlementState::Pending);
});

it('never downgrades an active grant back to pending when the form is submitted again', function () {
    // A contact who already has access keeps it, even if they re-opt-in while a
    // fresh confirmation is still open. The source system got this right and a
    // naive updateOrCreate would get it wrong.
    Entitlements::grant($this->contact, 'warmup-guide', 'newsletter_optin', 'warmup-guide');

    $again = Entitlements::grantPending($this->contact, 'warmup-guide', 'newsletter_optin', 'warmup-guide');

    expect($again->state())->toBe(EntitlementState::Active)
        ->and(Entitlements::allows($this->contact, 'warmup-guide'))->toBeTrue()
        ->and(Entitlement::query()->count())->toBe(1);
});

it('lifts a pending grant when the same grant arrives as a purchase', function () {
    Event::fake([EntitlementGranted::class]);

    $grant = Entitlements::grantPending($this->contact, 'warmup-guide', 'newsletter_optin', 'warmup-guide');

    $granted = Entitlements::grant($this->contact, 'warmup-guide', 'newsletter_optin', 'warmup-guide');

    expect($granted->state())->toBe(EntitlementState::Active)
        ->and($granted->getKey())->toBe($grant->getKey())
        ->and(Entitlement::query()->count())->toBe(1);

    Event::assertDispatchedTimes(EntitlementGranted::class, 1);
});

it('cannot be claimed once it has been revoked', function () {
    $grant = Entitlements::grantPending($this->contact, 'warmup-guide', 'newsletter_optin', 'warmup-guide');

    Entitlements::revoke($grant, 'Address bounced');

    expect(Entitlements::claimPending($grant->fresh()))->toBeFalse()
        ->and(Entitlements::allows($this->contact, 'warmup-guide'))->toBeFalse();
});

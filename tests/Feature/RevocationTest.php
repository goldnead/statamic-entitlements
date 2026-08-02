<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Events\EntitlementGranted;
use Goldnead\Entitlements\Events\EntitlementRevoked;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\IdentityContracts\Identity;
use Illuminate\Support\Facades\Event;

/**
 * Defect (c), the write half.
 *
 * The source system had a `revoked` status nobody ever assigned and a
 * `revoked_at` column every write path set to NULL. Production held forty-eight
 * grants and zero revocations — not because none were needed, but because there
 * was no way to record one. A state with no writer is not a state.
 */
beforeEach(function () {
    $this->subject = new SubjectReference('user', '42');
});

it('actually takes access away, and writes both signals', function () {
    $grant = Entitlements::grant($this->subject, 'course-a', 'thrivecart', 'evt_1');

    expect($grant->grantsAccess())->toBeTrue();

    $revoked = Entitlements::revoke($grant, 'Chargeback on order 1234');

    $grant->refresh();

    expect($revoked)->toBeTrue()
        ->and($grant->state())->toBe(EntitlementState::Revoked)
        ->and($grant->grantsAccess())->toBeFalse()
        ->and($grant->status)->toBe(EntitlementState::Revoked->value)
        ->and($grant->revoked_at)->not->toBeNull()
        ->and($grant->revoked_reason)->toBe('Chargeback on order 1234')
        ->and(Entitlements::allows($this->subject, 'course-a'))->toBeFalse();
});

it('refuses a revocation with no reason', function () {
    $grant = Entitlements::grant($this->subject, 'course-a', 'manual');

    expect(fn () => Entitlements::revoke($grant, '   '))
        ->toThrow(InvalidArgumentException::class);

    expect($grant->fresh()->grantsAccess())->toBeTrue();
});

it('fires EntitlementRevoked once, with the reason, the previous state and the actor', function () {
    Event::fake([EntitlementRevoked::class]);

    $grant = Entitlements::grant($this->subject, 'course-a', 'manual');
    $actor = Identity::user(7, 'support@example.test', 'Support');

    expect(Entitlements::revoke($grant, 'Refunded', $actor))->toBeTrue();

    Event::assertDispatched(EntitlementRevoked::class, function (EntitlementRevoked $event) use ($grant): bool {
        return $event->entitlement->is($grant)
            && $event->reason === 'Refunded'
            && $event->previousState === EntitlementState::Active
            && $event->actor?->userId === '7';
    });

    Event::assertDispatchedTimes(EntitlementRevoked::class, 1);
});

/**
 * The conditional UPDATE and its affected-row count are the concurrency guard.
 * Two support people clicking the same button, or a webhook retried after a
 * timeout, must produce one revocation and one event — otherwise whatever
 * listens to it does its work twice.
 */
it('lets only one of two simultaneous revocations win', function () {
    Event::fake([EntitlementRevoked::class]);

    $grant = Entitlements::grant($this->subject, 'course-a', 'manual');

    // Two callers holding their own instance of the same row, both convinced it
    // is still active — which is exactly what two processes see.
    $first = Entitlement::query()->findOrFail($grant->getKey());
    $second = Entitlement::query()->findOrFail($grant->getKey());

    $firstWon = Entitlements::revoke($first, 'Refunded');
    $secondWon = Entitlements::revoke($second, 'Refunded again');

    expect($firstWon)->toBeTrue()
        ->and($secondWon)->toBeFalse()
        ->and($grant->fresh()->revoked_reason)->toBe('Refunded');

    Event::assertDispatchedTimes(EntitlementRevoked::class, 1);
});

/**
 * The rule that makes a revocation mean something: a retried webhook must not
 * quietly undo a refund. `grant()` is the one call that could, so it is the one
 * that must not.
 */
it('does not let a repeated grant resurrect a revoked entitlement', function () {
    Event::fake([EntitlementGranted::class]);

    $grant = Entitlements::grant($this->subject, 'course-a', 'thrivecart', 'evt_1');
    Entitlements::revoke($grant, 'Refunded');

    Event::fake([EntitlementGranted::class]);

    $again = Entitlements::grant($this->subject, 'course-a', 'thrivecart', 'evt_1');

    expect($again->state())->toBe(EntitlementState::Revoked)
        ->and($again->grantsAccess())->toBeFalse()
        ->and(Entitlement::query()->count())->toBe(1);

    Event::assertNotDispatched(EntitlementGranted::class);
});

it('restores access only when somebody asks for it, and says so', function () {
    Event::fake([EntitlementGranted::class]);

    $grant = Entitlements::grant($this->subject, 'course-a', 'manual');
    Entitlements::revoke($grant, 'Refunded');

    expect(Entitlements::restore($grant->fresh()))->toBeTrue();

    $grant->refresh();

    expect($grant->state())->toBe(EntitlementState::Active)
        ->and($grant->grantsAccess())->toBeTrue()
        ->and($grant->revoked_at)->toBeNull()
        // The reason is cleared with the revocation; the fact that it happened
        // lives in the event and, if installed, in the activity ledger. A grant
        // row is a current state, not a log.
        ->and($grant->revoked_reason)->toBeNull();

    Event::assertDispatched(EntitlementGranted::class, fn (EntitlementGranted $e): bool => $e->previousState === EntitlementState::Revoked);
});

it('does not announce a restore that lands on an already-expired grant', function () {
    Event::fake([EntitlementGranted::class]);

    $grant = Entitlements::grant(
        $this->subject,
        'course-a',
        'manual',
        expiresAt: now()->subDay(),
    );

    Entitlements::revoke($grant, 'Refunded');
    Entitlements::restore($grant->fresh());

    expect($grant->fresh()->state())->toBe(EntitlementState::Expired);

    Event::assertNotDispatched(EntitlementGranted::class);
});

it('is a no-op when restoring something that was never revoked', function () {
    $grant = Entitlements::grant($this->subject, 'course-a', 'manual');

    expect(Entitlements::restore($grant))->toBeFalse();
});

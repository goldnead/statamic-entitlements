<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Events\EntitlementExpired;
use Goldnead\Entitlements\Events\EntitlementGranted;
use Goldnead\Entitlements\Events\EntitlementPending;
use Goldnead\Entitlements\Events\EntitlementRevoked;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\IdentityContracts\Identity;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->subject = new SubjectReference('user', '42');
});

/**
 * The single most important thing this package does not do.
 *
 * In the system it was extracted from, `EntitlementProvisioner::provision()`
 * created a user account, wrote the grant, recorded a welcome-mail debt, issued
 * a magic-link token and sent the mail — all inside one call. A grant could not
 * be written without a mailer, and a mail failure could take down a paid
 * checkout.
 *
 * Three of those four concerns stayed behind in the application. What crosses is
 * an event. This test is the boundary, asserted rather than described: nothing
 * is sent, nothing is notified, and no user is created.
 */
it('sends nothing at all when access is granted', function () {
    Mail::fake();
    Notification::fake();

    Entitlements::grant($this->subject, 'course-a', 'thrivecart', 'evt_1');

    Mail::assertNothingSent();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});

it('sends nothing when a grant is parked, claimed, revoked or expires', function () {
    Mail::fake();
    Notification::fake();

    $grant = Entitlements::grantPending($this->subject, 'lead-magnet', 'newsletter_optin', 'lead-magnet');
    Entitlements::claimPending($grant);
    Entitlements::revoke($grant->fresh(), 'Refunded');

    $expiring = Entitlements::grant($this->subject, 'course-b', 'manual', expiresAt: now()->addDay());
    $this->travelTo(now()->addWeek());
    $this->artisan('entitlements:announce')->assertSuccessful();

    expect($expiring->fresh()->state())->toBe(EntitlementState::Expired);

    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});

it('fires EntitlementGranted with the actor when a grant becomes active', function () {
    Event::fake([EntitlementGranted::class]);

    $actor = Identity::system('mollie-webhook');

    $grant = Entitlements::grant($this->subject, 'course-a', 'mollie', 'tr_1', actor: $actor);

    Event::assertDispatched(EntitlementGranted::class, function (EntitlementGranted $event) use ($grant): bool {
        return $event->entitlement->is($grant)
            && $event->previousState === null
            && $event->actor?->type === Identity::TYPE_SYSTEM;
    });

    Event::assertDispatchedTimes(EntitlementGranted::class, 1);
});

it('fires nothing when the same grant arrives a second time', function () {
    Entitlements::grant($this->subject, 'course-a', 'mollie', 'tr_1');

    Event::fake([EntitlementGranted::class, EntitlementPending::class]);

    Entitlements::grant($this->subject, 'course-a', 'mollie', 'tr_1');

    Event::assertNothingDispatched();
});

it('fires EntitlementPending, and no grant event, when a grant is parked', function () {
    Event::fake([EntitlementGranted::class, EntitlementPending::class]);

    $grant = Entitlements::grantPending($this->subject, 'lead-magnet', 'newsletter_optin', 'lead-magnet');

    expect($grant->state())->toBe(EntitlementState::Pending)
        ->and($grant->grantsAccess())->toBeFalse();

    Event::assertDispatchedTimes(EntitlementPending::class, 1);
    Event::assertNotDispatched(EntitlementGranted::class);
});

/**
 * A scheduled grant is an announcement, not an event. Firing EntitlementGranted
 * when the grant is *written* would tell a listener that access exists when it
 * does not — and the listener is typically what sends the "here is your access"
 * mail.
 */
it('fires nothing for a grant that starts in the future, then fires when it starts', function () {
    Event::fake([EntitlementGranted::class]);

    $grant = Entitlements::grant($this->subject, 'course-a', 'manual', startsAt: now()->addWeek());

    expect($grant->state())->toBe(EntitlementState::Scheduled);

    Event::assertNotDispatched(EntitlementGranted::class);

    $this->travelTo(now()->addDays(8));
    $this->artisan('entitlements:announce')->assertSuccessful();

    expect($grant->fresh()->state())->toBe(EntitlementState::Active);

    Event::assertDispatched(EntitlementGranted::class, fn (EntitlementGranted $e): bool => $e->previousState === EntitlementState::Scheduled);
    Event::assertDispatchedTimes(EntitlementGranted::class, 1);
});

it('announces an expiry once, however often the pass runs', function () {
    Event::fake([EntitlementExpired::class]);

    $grant = Entitlements::grant($this->subject, 'course-a', 'manual', expiresAt: now()->addDay());

    $this->artisan('entitlements:announce')->assertSuccessful();

    Event::assertNotDispatched(EntitlementExpired::class);

    $this->travelTo(now()->addWeek());

    $this->artisan('entitlements:announce')->assertSuccessful();
    $this->artisan('entitlements:announce')->assertSuccessful();
    $this->artisan('entitlements:announce')->assertSuccessful();

    Event::assertDispatchedTimes(EntitlementExpired::class, 1);

    Event::assertDispatched(EntitlementExpired::class, function (EntitlementExpired $event) use ($grant): bool {
        return $event->entitlement->is($grant)
            && $event->grantedAccessUntil !== null;
    });
});

it('reports the end of a grace period as the moment access actually ended', function () {
    Event::fake([EntitlementExpired::class]);

    $grant = Entitlements::grant($this->subject, 'course-a', 'manual', expiresAt: now()->addDay());
    $graceUntil = now()->addWeek();

    Entitlements::enterGracePeriod($grant, $graceUntil);

    $this->travelTo(now()->addWeeks(2));
    $this->artisan('entitlements:announce')->assertSuccessful();

    Event::assertDispatched(EntitlementExpired::class, function (EntitlementExpired $event) use ($graceUntil): bool {
        // grace_until, not expires_at: a listener that re-derives this gets it
        // wrong for exactly the case where it matters.
        return $event->grantedAccessUntil?->format('Y-m-d H:i') === $graceUntil->utc()->format('Y-m-d H:i');
    });
});

it('does not announce the expiry of a grant that was revoked before it ran out', function () {
    Event::fake([EntitlementExpired::class, EntitlementRevoked::class]);

    $grant = Entitlements::grant($this->subject, 'course-a', 'manual', expiresAt: now()->addDay());
    Entitlements::revoke($grant, 'Refunded');

    $this->travelTo(now()->addWeek());
    $this->artisan('entitlements:announce')->assertSuccessful();

    Event::assertNotDispatched(EntitlementExpired::class);
});

it('does not announce a grant imported as already expired', function () {
    // Backfilling historical purchases must not tell every past customer their
    // access has just ended.
    Event::fake([EntitlementExpired::class, EntitlementGranted::class]);

    Entitlements::grant(
        $this->subject,
        'course-a',
        'import',
        'legacy-1',
        startsAt: now()->subYear(),
        expiresAt: now()->subMonths(6),
    );

    $this->artisan('entitlements:announce')->assertSuccessful();

    Event::assertNothingDispatched();
});

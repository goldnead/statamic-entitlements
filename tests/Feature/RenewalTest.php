<?php

use Carbon\CarbonImmutable;
use Goldnead\Entitlements\EntitlementManager;
use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Events\EntitlementGranted;
use Goldnead\Entitlements\Events\EntitlementRenewed;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\Event;

/**
 * A subscription that renews every month must not create a grant every month.
 *
 * `grant()` refuses to widen an existing window on purpose — a retry is not a
 * renewal. That rule is right and it leaves a gap: a billing cycle calling
 * `grant()` either does nothing or writes a second row, and after a year the
 * question "does this person have access" has twelve answers.
 */
beforeEach(function () {
    $this->subject = new SubjectReference('email', 'wer@example.com');
});

/**
 * Event::fake() nach dem ersten Aufruf ist wirkungslos.
 *
 * Der Manager bekommt den Dispatcher injiziert und ist ein Singleton, hält also
 * den echten fest, während der Container längst die Attrappe führt. Ein
 * `assertNotDispatched` ist dann grün, weil nichts ankommt — nicht, weil nichts
 * gefeuert hat. Deshalb wird hier gefaked und danach neu aufgelöst.
 */
function mitAttrappe(array $events): void
{
    Event::fake($events);

    app()->forgetInstance(EntitlementManager::class);
    Entitlements::clearResolvedInstances();
}

it('pushes the window forward instead of writing a second row', function () {
    Entitlements::grant($this->subject, 'mitgliedschaft', 'payments', 'tr_1',
        expiresAt: CarbonImmutable::parse('2026-09-01 00:00', 'UTC'));

    Entitlements::renew($this->subject, 'mitgliedschaft', CarbonImmutable::parse('2026-10-01 00:00', 'UTC'));

    expect(Entitlement::count())->toBe(1)
        ->and(Entitlement::first()->expires_at->format('Y-m-d'))->toBe('2026-10-01');
});

it('never shortens a window somebody paid for', function () {
    // Eine verspätete Zustellung trägt ein altes Datum. Die Zeit ist bezahlt.
    Entitlements::grant($this->subject, 'mitgliedschaft', 'payments', 'tr_1',
        expiresAt: CarbonImmutable::parse('2026-12-01 00:00', 'UTC'));

    mitAttrappe([EntitlementRenewed::class]);

    Entitlements::renew($this->subject, 'mitgliedschaft', CarbonImmutable::parse('2026-10-01 00:00', 'UTC'));

    expect(Entitlement::first()->expires_at->format('Y-m-d'))->toBe('2026-12-01');

    // Und schweigt dabei: nichts ist passiert, also gibt es nichts zu melden.
    Event::assertNotDispatched(EntitlementRenewed::class);
});

/**
 * Relative Daten, nicht feste — `grace_until` wird gegen `now()` verglichen
 * (`src/Casts/UtcDateTime.php:14`). Mit `2026-09-08 00:00` als Gnadenfrist war
 * dieser Test eine Zeitbombe: er lief bis zum 07.09.2026 grün und war am
 * 08.09.2026 rot, ohne dass jemand etwas geändert hatte. Der Rest der Suite
 * rechnet ohnehin relativ (`GrantEventsTest.php:52`).
 */
it('lifts a grace period, because payment is what it was waiting for', function () {
    $grant = Entitlements::grant($this->subject, 'mitgliedschaft', 'payments', 'tr_1',
        expiresAt: CarbonImmutable::now()->subWeek());

    Entitlements::enterGracePeriod($grant, CarbonImmutable::now()->addWeek());

    expect($grant->fresh()->state())->toBe(EntitlementState::GracePeriod);

    Entitlements::renew($this->subject, 'mitgliedschaft', CarbonImmutable::now()->addMonth());

    expect($grant->fresh()->state())->toBe(EntitlementState::Active)
        ->and($grant->fresh()->grace_until)->toBeNull();
});

it('refuses to bring back access that was taken away deliberately', function () {
    // Eine Rückerstattung wird nicht durch die nächste Abbuchung geheilt.
    // Dafür gibt es restore(), und das ist die Entscheidung eines Menschen.
    $grant = Entitlements::grant($this->subject, 'mitgliedschaft', 'payments', 'tr_1',
        expiresAt: CarbonImmutable::parse('2026-09-01 00:00', 'UTC'));

    Entitlements::revoke($grant, 'Rückerstattung');

    expect(Entitlements::renew($this->subject, 'mitgliedschaft', CarbonImmutable::parse('2026-10-01 00:00', 'UTC')))
        ->toBeNull()
        ->and($grant->fresh()->state())->toBe(EntitlementState::Revoked);
});

it('says so when there is nothing to renew, so the caller can grant instead', function () {
    expect(Entitlements::renew($this->subject, 'gibt-es-nicht', CarbonImmutable::parse('2026-10-01 00:00', 'UTC')))
        ->toBeNull();
});

it('announces a renewal as its own thing, not as a new grant', function () {
    // Ein Listener, der Leute begrüßt, würde sonst jeden Monat grüßen.
    Entitlements::grant($this->subject, 'mitgliedschaft', 'payments', 'tr_1',
        expiresAt: CarbonImmutable::parse('2026-09-01 00:00', 'UTC'));

    mitAttrappe([EntitlementRenewed::class, EntitlementGranted::class]);

    Entitlements::renew($this->subject, 'mitgliedschaft', CarbonImmutable::parse('2026-10-01 00:00', 'UTC'));

    Event::assertDispatchedTimes(EntitlementRenewed::class, 1);
    Event::assertNotDispatched(EntitlementGranted::class);

    Event::assertDispatched(EntitlementRenewed::class, function (EntitlementRenewed $e) {
        // Bis wann es vorher lief, ist die Frage einer Prüfung — und die Zeile
        // beantwortet sie nachher nicht mehr.
        return $e->previousExpiresAt?->format('Y-m-d') === '2026-09-01';
    });
});

it('renews the grant that runs longest when a subject has more than one', function () {
    Entitlements::grant($this->subject, 'mitgliedschaft', 'payments', 'alt',
        expiresAt: CarbonImmutable::parse('2026-07-01 00:00', 'UTC'));
    Entitlements::grant($this->subject, 'mitgliedschaft', 'payments', 'neu',
        expiresAt: CarbonImmutable::parse('2026-09-01 00:00', 'UTC'));

    Entitlements::renew($this->subject, 'mitgliedschaft', CarbonImmutable::parse('2026-10-01 00:00', 'UTC'));

    expect(Entitlement::where('source_ref', 'neu')->first()->expires_at->format('Y-m-d'))->toBe('2026-10-01')
        ->and(Entitlement::where('source_ref', 'alt')->first()->expires_at->format('Y-m-d'))->toBe('2026-07-01');
});

/**
 * Lebenslang ist das längste Fenster, das es gibt.
 *
 * Die Regel „nie verkürzen" verglich nur, wenn schon ein Datum da war. Ein
 * Zugang ohne `expires_at` bekam beim Renew eines, lief damit ab, und ein Kauf
 * auf Lebenszeit wurde still zum Abo.
 */
it('never puts an end date on a lifetime grant', function () {
    $grant = Entitlements::grant($this->subject, 'mitgliedschaft', 'payments', 'lifetime');

    mitAttrappe([EntitlementRenewed::class]);

    $renewed = Entitlements::renew($this->subject, 'mitgliedschaft', CarbonImmutable::now()->addMonth());

    expect($renewed?->getKey())->toBe($grant->getKey())
        ->and($grant->fresh()->expires_at)->toBeNull()
        ->and($grant->fresh()->state())->toBe(EntitlementState::Active);

    Event::assertNotDispatched(EntitlementRenewed::class);
});

/**
 * NULL sortiert je Datenbank verschieden: SQLite und MySQL legen es bei DESC
 * ans Ende, PostgreSQL an den Anfang. Ohne explizite Regel hing es an der
 * Datenbank, welche Zeile verlängert wurde. Lebenslang zählt als längstes.
 */
it('treats a lifetime grant as the one that runs longest, on every database', function () {
    $lifetime = Entitlements::grant($this->subject, 'mitgliedschaft', 'payments', 'lifetime');
    Entitlements::grant($this->subject, 'mitgliedschaft', 'payments', 'abo',
        expiresAt: CarbonImmutable::now()->addWeek());

    mitAttrappe([EntitlementRenewed::class]);

    $renewed = Entitlements::renew($this->subject, 'mitgliedschaft', CarbonImmutable::now()->addMonth());

    expect($renewed?->getKey())->toBe($lifetime->getKey())
        ->and(Entitlement::where('source_ref', 'lifetime')->first()->expires_at)->toBeNull()
        ->and(Entitlement::where('source_ref', 'abo')->first()->expires_at->format('Y-m-d'))
        ->toBe(CarbonImmutable::now()->addWeek()->utc()->format('Y-m-d'));

    Event::assertNotDispatched(EntitlementRenewed::class);
});

/**
 * Pending heißt: noch nicht bestätigt. Renew hob so eine Zeile auf Active, der
 * Resolver gewährte Zugang, und `EntitlementGranted` kam nie. Das Double-Opt-in
 * war übersprungen. Eine unbestätigte Zeile ist nichts, was sich verlängern lässt.
 */
it('does not turn an unconfirmed grant into access by renewing it', function () {
    $pending = Entitlements::grantPending($this->subject, 'mitgliedschaft', 'newsletter_optin', 'optin',
        expiresAt: CarbonImmutable::now()->addWeek());

    mitAttrappe([EntitlementRenewed::class, EntitlementGranted::class]);

    expect(Entitlements::renew($this->subject, 'mitgliedschaft', CarbonImmutable::now()->addMonth()))->toBeNull()
        ->and($pending->fresh()->state())->toBe(EntitlementState::Pending)
        ->and(Entitlements::decide($this->subject, 'mitgliedschaft')->allowed)->toBeFalse();

    Event::assertNotDispatched(EntitlementRenewed::class);
});

it('renews the confirmed grant and leaves an unconfirmed one alone', function () {
    $paid = Entitlements::grant($this->subject, 'mitgliedschaft', 'payments', 'abo',
        expiresAt: CarbonImmutable::now()->addWeek());
    $pending = Entitlements::grantPending($this->subject, 'mitgliedschaft', 'newsletter_optin', 'optin',
        expiresAt: CarbonImmutable::now()->addYear());

    $renewed = Entitlements::renew($this->subject, 'mitgliedschaft', CarbonImmutable::now()->addMonth());

    expect($renewed?->getKey())->toBe($paid->getKey())
        ->and($pending->fresh()->state())->toBe(EntitlementState::Pending);
});

it('does not put an unconfirmed grant into a grace period', function () {
    $pending = Entitlements::grantPending($this->subject, 'mitgliedschaft', 'newsletter_optin', 'optin');

    expect(Entitlements::enterGracePeriod($pending, CarbonImmutable::now()->addWeek()))->toBeFalse()
        ->and($pending->fresh()->state())->toBe(EntitlementState::Pending)
        ->and(Entitlements::decide($this->subject, 'mitgliedschaft')->allowed)->toBeFalse();
});

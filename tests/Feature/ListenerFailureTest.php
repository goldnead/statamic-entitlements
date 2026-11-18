<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Events\EntitlementExpired;
use Goldnead\Entitlements\Events\EntitlementGranted;
use Goldnead\Entitlements\Events\EntitlementRevoked;
use Goldnead\Entitlements\Events\UsageReset;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Models\Usage;
use Goldnead\Entitlements\Support\ListenerFailed;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

/**
 * Der Claim steht, bevor das Event rausgeht. Warf ein Listener (die Mail
 * „dein Zugang ist abgelaufen" zum Beispiel), galt der Übergang trotzdem als
 * angekündigt und wurde nie wieder versucht. Der Kunde bekam nie Bescheid,
 * und niemand erfuhr davon.
 *
 * Jetzt: je Zeile abgefangen, Claim zurückgesetzt, gemeldet. Der nächste Lauf
 * versucht es wieder, die übrigen Zeilen desselben Laufs gehen trotzdem raus.
 */
beforeEach(function () {
    $this->subject = new SubjectReference('user', '42');
});

/**
 * Ein Listener, der beim ersten Aufruf für die genannte Zeile wirft und danach
 * mitzählt. So lässt sich zeigen, dass der zweite Lauf nachholt.
 */
function wirftEinmal(string $event, ?int $nurFuer = null): stdClass
{
    $zaehler = new stdClass;
    $zaehler->gehoert = [];
    $zaehler->geworfen = false;

    Event::listen($event, function (object $e) use ($zaehler, $nurFuer): void {
        $id = property_exists($e, 'entitlement') ? $e->entitlement->getKey() : null;

        if (! $zaehler->geworfen && ($nurFuer === null || $id === $nurFuer)) {
            $zaehler->geworfen = true;

            throw new RuntimeException('Mailserver weg');
        }

        $zaehler->gehoert[] = $id;
    });

    return $zaehler;
}

it('retries an expiry whose listener threw, and still announces the rest of the run', function () {
    Exceptions::fake();

    $erste = Entitlements::grant($this->subject, 'kurs-a', 'manual', 'a', expiresAt: now()->addDay());
    $zweite = Entitlements::grant($this->subject, 'kurs-b', 'manual', 'b', expiresAt: now()->addDay());

    $zaehler = wirftEinmal(EntitlementExpired::class, $erste->getKey());

    $this->travelTo(now()->addWeek());

    $this->artisan('entitlements:announce')->assertSuccessful();

    expect($erste->fresh()->announced_state)->toBe(EntitlementState::Active->value)
        ->and($zweite->fresh()->announced_state)->toBe(EntitlementState::Expired->value)
        ->and($zaehler->gehoert)->toBe([$zweite->getKey()]);

    Exceptions::assertReported(fn (ListenerFailed $e): bool => $e->context()['entitlement_id'] === $erste->getKey()
        && $e->getPrevious()?->getMessage() === 'Mailserver weg');

    $this->artisan('entitlements:announce')->assertSuccessful();

    expect($erste->fresh()->announced_state)->toBe(EntitlementState::Expired->value)
        ->and($zaehler->gehoert)->toBe([$zweite->getKey(), $erste->getKey()]);
});

it('retries an activation whose listener threw', function () {
    Exceptions::fake();

    $geplant = Entitlements::grant($this->subject, 'kurs-a', 'manual', 'a', startsAt: now()->addDay());

    $zaehler = wirftEinmal(EntitlementGranted::class);

    $this->travelTo(now()->addWeek());

    $this->artisan('entitlements:announce')->assertSuccessful();

    expect($geplant->fresh()->announced_state)->toBeNull();
    Exceptions::assertReported(ListenerFailed::class);

    $this->artisan('entitlements:announce')->assertSuccessful();

    expect($geplant->fresh()->announced_state)->toBe(EntitlementState::Active->value)
        ->and($zaehler->gehoert)->toBe([$geplant->getKey()]);
});

it('retries a usage reset whose listener threw', function () {
    Exceptions::fake();

    $anna = new SubjectReference('user', '1');

    $this->travelTo(now()->setDate(2026, 3, 14)->setTime(10, 0));
    Entitlements::setLimits('solo', ['analyses' => ['value' => 10, 'period' => 'month']]);
    Entitlements::grant($anna, 'solo', 'manual');
    Entitlements::consume($anna, 'analyses', 4);

    $zaehler = wirftEinmal(UsageReset::class);

    $this->travelTo(now()->setDate(2026, 4, 14)->setTime(10, 1));

    $this->artisan('entitlements:announce')->assertSuccessful();

    expect(Usage::query()->whereNotNull('rolled_over_at')->count())->toBe(0);
    Exceptions::assertReported(fn (ListenerFailed $e): bool => $e->context()['limit_key'] === 'analyses');

    $this->artisan('entitlements:announce')->assertSuccessful();

    expect(Usage::query()->whereNotNull('rolled_over_at')->count())->toBe(1)
        ->and($zaehler->gehoert)->toHaveCount(1);
});

/**
 * claimPending gibt einem Retry der Queue die Chance zu gewinnen: die Zeile
 * geht zurück auf Pending, die Exception geht an den Aufrufer.
 */
it('hands a confirmation back to pending when its listener throws, so the retry wins', function () {
    $grant = Entitlements::grantPending($this->subject, 'warmup-guide', 'newsletter_optin', 'warmup-guide');

    $zaehler = wirftEinmal(EntitlementGranted::class);

    expect(fn () => Entitlements::claimPending($grant))->toThrow(RuntimeException::class, 'Mailserver weg');

    expect($grant->fresh()->state())->toBe(EntitlementState::Pending)
        ->and(Entitlements::decide($this->subject, 'warmup-guide')->allowed)->toBeFalse();

    expect(Entitlements::claimPending($grant->fresh()))->toBeTrue()
        ->and($grant->fresh()->state())->toBe(EntitlementState::Active)
        ->and($zaehler->gehoert)->toBe([$grant->getKey()]);
});

/**
 * Ein Entzug bleibt ein Entzug, auch wenn die Mail dazu scheitert: fail closed.
 * Aber er wird gemeldet, statt den Aufrufer mit einer halben Antwort stehen zu
 * lassen.
 */
it('keeps a revocation when its listener throws, and reports it', function () {
    Exceptions::fake();

    $grant = Entitlements::grant($this->subject, 'kurs-a', 'manual', 'a');

    wirftEinmal(EntitlementRevoked::class);

    expect(Entitlements::revoke($grant, 'Rückerstattung'))->toBeTrue()
        ->and($grant->fresh()->state())->toBe(EntitlementState::Revoked)
        ->and(Entitlement::query()->whereKey($grant->getKey())->value('announced_state'))->toBe(EntitlementState::Revoked->value);

    Exceptions::assertReported(fn (ListenerFailed $e): bool => $e->context()['entitlement_id'] === $grant->getKey());
});

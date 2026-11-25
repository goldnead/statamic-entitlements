<?php

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Usage;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Grenzfälle des Kontingent-Managers: fremde Zählungen, Eingaben unter null
 * und null, Stock-Schlüssel bei Rückgabe und Zurücksetzen, fehlende Tabellen.
 *
 * Die Schlüssel und Pläne stammen aus QuotaTest.php: `analyses` ist ein
 * Nutzungslimit mit Zeitraum Jahr, `arrangements` ein Bestandslimit.
 */
beforeEach(function () {
    config()->set('entitlements.limits.keys', [
        'analyses' => ['label' => 'Analysen', 'period' => 'year'],
        'arrangements' => ['label' => 'Arrangements'],
    ]);

    Entitlements::setLimits('solo', ['analyses' => 10, 'arrangements' => 3]);

    $this->anna = new SubjectReference('user', '1');
});

/**
 * Ein Stock-Zähler, den es im Normalbetrieb nicht gibt: der Aufrufer zählt
 * Bestände selbst. Die Zeile existiert nur, damit ein kaputter Pfad, der sie
 * anfasst, es in der Datenbank sieht.
 */
function stockCounterFor(SubjectReference $subject, int $used): void
{
    Usage::query()->forceCreate([
        'subject_type' => $subject->type,
        'subject_id' => $subject->id,
        'limit_key' => 'arrangements',
        'period_key' => 'stock',
        'used' => $used,
    ]);
}

it('answers withinLimit on a usage key from the own counter, not from the count the caller passes', function () {
    Entitlements::grant($this->anna, 'solo', 'manual');

    // Eigener Zähler bei 0 von 10: die übergebene Zählung 999 darf nichts entscheiden.
    expect(Entitlements::withinLimit($this->anna, 'analyses', 999))->toBeTrue();

    Entitlements::consume($this->anna, 'analyses', 10);

    // Eigener Zähler voll: eine übergebene Zählung 0 darf nicht freigeben.
    expect(Entitlements::withinLimit($this->anna, 'analyses', 0))->toBeFalse()
        ->and(Entitlements::remaining($this->anna, 'analyses'))->toBe(0);
});

it('refuses a negative count in withinLimit, for the count and for what is being added', function () {
    Entitlements::grant($this->anna, 'solo', 'manual');

    expect(fn () => Entitlements::withinLimit($this->anna, 'arrangements', -1))
        ->toThrow(InvalidArgumentException::class, 'Counts cannot be negative.');

    expect(fn () => Entitlements::withinLimit($this->anna, 'arrangements', 1, -1))
        ->toThrow(InvalidArgumentException::class, 'Counts cannot be negative.');
});

it('refuses to consume nothing and books nothing for it', function () {
    Entitlements::grant($this->anna, 'solo', 'manual');

    expect(fn () => Entitlements::consume($this->anna, 'analyses', 0))
        ->toThrow(InvalidArgumentException::class, 'Consume at least 1.');

    expect(Usage::query()->count())->toBe(0);
});

it('gives back nothing on a stock key without a receipt, and leaves the counter alone', function () {
    Entitlements::grant($this->anna, 'solo', 'manual');
    stockCounterFor($this->anna, 2);

    expect(Entitlements::release($this->anna, 'arrangements'))->toBeFalse()
        ->and(Usage::query()->where('limit_key', 'arrangements')->value('used'))->toBe(2);
});

it('does not reset a stock key, and leaves the counter alone', function () {
    Entitlements::grant($this->anna, 'solo', 'manual');
    stockCounterFor($this->anna, 2);

    expect(Entitlements::resetUsage($this->anna, 'arrangements'))->toBeFalse()
        ->and(Usage::query()->where('limit_key', 'arrangements')->value('used'))->toBe(2);
});

it('answers null from consume while the limit tables are missing, and does not throw', function () {
    Entitlements::grant($this->anna, 'solo', 'manual');

    // Dieselbe Reihenfolge wie dropEntitlementTables() in SetupGuardTest: die
    // Tabellen mit Verweisen zuerst.
    Schema::dropIfExists('entitlement_usage_receipts');
    Schema::dropIfExists('entitlement_usages');
    Schema::dropIfExists('entitlement_limits');

    Log::spy();

    expect(Entitlements::consume($this->anna, 'analyses'))->toBeNull();

    // Das Schweigen darf nicht still sein: die Meldung nennt den Weg raus.
    Log::shouldHaveReceived('error')
        ->withArgs(fn ($m) => is_string($m) && str_contains($m, 'limit tables do not exist'))
        ->once();
});

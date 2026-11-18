<?php

use Goldnead\Entitlements\Events\LimitReached;
use Goldnead\Entitlements\Events\UsageConsumed;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Limits\UsageReceipt;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

/**
 * Die Buchung ist committet, bevor die Events rausgehen. Warf ein Listener
 * danach, bekam der Aufrufer keinen Beleg und konnte die Buchung nie
 * zurückgeben: ein gescheiterter Job hätte dem Kunden ein Kontingent
 * weggenommen, für immer, und niemand hätte es gesehen.
 *
 * Jetzt geht der Beleg immer zurück, und der Fehler wird gemeldet.
 */
beforeEach(function () {
    $this->anna = new SubjectReference('user', '1');
});

it('hands back the receipt when a usage listener throws, so the booking can be given back', function () {
    Exceptions::fake();

    Entitlements::setLimits('solo', ['analyses' => ['value' => 5, 'period' => 'year']]);
    Entitlements::grant($this->anna, 'solo', 'manual');

    Event::listen(UsageConsumed::class, function (): void {
        throw new RuntimeException('Statistik weg');
    });

    $receipt = Entitlements::consume($this->anna, 'analyses', 2);

    expect($receipt)->toBeInstanceOf(UsageReceipt::class)
        ->and(Entitlements::quota($this->anna, 'analyses')->used)->toBe(2);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Statistik weg');

    expect(Entitlements::release($this->anna, 'analyses', receipt: $receipt))->toBeTrue()
        ->and(Entitlements::quota($this->anna, 'analyses')->used)->toBe(0);
});

it('still announces the limit when a usage listener throws, and hands back the receipt when that one throws too', function () {
    Exceptions::fake();

    Entitlements::setLimits('solo', ['analyses' => ['value' => 1, 'period' => 'year']]);
    Entitlements::grant($this->anna, 'solo', 'manual');

    $reached = 0;

    Event::listen(UsageConsumed::class, function (): void {
        throw new RuntimeException('Statistik weg');
    });

    Event::listen(LimitReached::class, function () use (&$reached): void {
        $reached++;

        throw new RuntimeException('Mailserver weg');
    });

    $receipt = Entitlements::consume($this->anna, 'analyses');

    expect($receipt)->toBeInstanceOf(UsageReceipt::class)
        ->and($reached)->toBe(1);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Statistik weg');
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Mailserver weg');
});

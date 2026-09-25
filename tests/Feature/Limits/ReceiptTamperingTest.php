<?php

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Usage;
use Goldnead\Entitlements\Support\SubjectExtensions;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Str;

/**
 * Kritik R2 (25.09.2026): `release(receipt:)` glaubte dem Array. Die Proben
 * des Kritikers als Negativtests. Seitdem entscheidet allein die beim
 * consume() hinterlegte Zeile; aus dem Array zählt nur die id.
 */
beforeEach(function () {
    $this->anna = new SubjectReference('user', '1');
    $this->eve = new SubjectReference('user', '2');
    Entitlements::setLimits('solo', ['analyses' => ['value' => 10, 'period' => 'year']]);
    Entitlements::grant($this->anna, 'solo', 'manual');
    Entitlements::grant($this->eve, 'solo', 'manual');
    Entitlements::consume($this->anna, 'analyses', 8);

    $this->used = fn (SubjectReference $s) => Entitlements::quota($s, 'analyses')->used;
});

it('P1: refuses a receipt forged onto somebody else\'s counter', function () {
    $mine = Entitlements::consume($this->eve, 'analyses', 1)->toArray();
    $forged = array_merge($mine, [
        'id' => (string) Str::uuid(),
        'holder_id' => '1',
        'amount' => 3,
        'period_key' => Usage::query()->where('subject_id', '1')->value('period_key'),
    ]);

    expect(Entitlements::release($this->eve, 'analyses', receipt: $forged))->toBeFalse()
        ->and(($this->used)($this->anna))->toBe(8);
});

it('P1b: refuses eve releasing her own real receipt onto anna by rewriting the holder', function () {
    $mine = Entitlements::consume($this->eve, 'analyses', 1)->toArray();

    expect(Entitlements::release($this->eve, 'analyses', receipt: array_merge($mine, ['holder_id' => '1'])))->toBeTrue()
        // The stored row decides: it goes back to eve, not to anna.
        ->and(($this->used)($this->anna))->toBe(8)
        ->and(($this->used)($this->eve))->toBe(0);
});

it('P2: does not release again under fresh ids', function () {
    $receipt = Entitlements::consume($this->anna, 'analyses', 1)->toArray();
    $released = 0;

    foreach (range(1, 5) as $i) {
        $released += (int) Entitlements::release($this->anna, 'analyses', receipt: array_merge($receipt, ['id' => (string) Str::uuid()]));
    }

    expect($released)->toBe(0)->and(($this->used)($this->anna))->toBe(9);
});

it('P3: ignores an inflated amount in the array', function () {
    $receipt = Entitlements::consume($this->anna, 'analyses', 1)->toArray();

    expect(Entitlements::release($this->anna, 'analyses', receipt: array_merge($receipt, ['amount' => 999999])))->toBeTrue()
        ->and(($this->used)($this->anna))->toBe(8);
});

it('P3b: refuses asking for more than was booked', function () {
    $receipt = Entitlements::consume($this->anna, 'analyses', 1);

    expect(Entitlements::release($this->anna, 'analyses', 5, receipt: $receipt))->toBeFalse()
        ->and(($this->used)($this->anna))->toBe(9);
});

it('P4: never raises the counter through a negative amount', function () {
    $receipt = Entitlements::consume($this->anna, 'analyses', 1)->toArray();

    expect(Entitlements::release($this->anna, 'analyses', receipt: array_merge($receipt, ['amount' => -5])))->toBeTrue()
        ->and(($this->used)($this->anna))->toBe(8);

    expect(fn () => Entitlements::release($this->anna, 'analyses', -5, receipt: $receipt))
        ->toThrow(InvalidArgumentException::class);
});

it('P5: takes the brand from the stored row and the current context, never from the array', function () {
    $this->enableMultiBrand();
    $alpha = $this->makeBrand('alpha');
    $beta = $this->makeBrand('beta');
    $context = app('brand-context');

    $receipt = $context->runFor($alpha, function () {
        Entitlements::setLimits('solo', ['analyses' => ['value' => 10, 'period' => 'year']]);
        Entitlements::grant($this->anna, 'solo', 'manual');

        return Entitlements::consume($this->anna, 'analyses', 2)->toArray();
    });

    // Presented in another brand, and with a made-up brand id: nothing.
    $context->runFor($beta, function () use ($receipt) {
        expect(Entitlements::release($this->anna, 'analyses', receipt: $receipt))->toBeFalse()
            ->and(Entitlements::release($this->anna, 'analyses', receipt: array_merge($receipt, ['brand_id' => 999])))->toBeFalse();
    });

    $context->runFor($alpha, function () use ($receipt) {
        expect(Entitlements::quota($this->anna, 'analyses')->used)->toBe(2)
            ->and(Entitlements::release($this->anna, 'analyses', receipt: array_merge($receipt, ['brand_id' => 999])))->toBeTrue()
            ->and(Entitlements::quota($this->anna, 'analyses')->used)->toBe(0);
    });
});

it('P6: refuses another subject and another key', function () {
    $receipt = Entitlements::consume($this->anna, 'analyses', 1);

    expect(Entitlements::release($this->eve, 'analyses', receipt: $receipt))->toBeFalse()
        ->and(Entitlements::release($this->anna, 'exports', receipt: $receipt))->toBeFalse()
        ->and(($this->used)($this->anna))->toBe(9);
});

it('P7: refuses with null, which `! consume()` and `=== null` read as refused', function () {
    Entitlements::consume($this->anna, 'analyses', 2);
    $refused = Entitlements::consume($this->anna, 'analyses', 1);

    expect($refused)->toBeNull()->and(! $refused)->toBeTrue();
});

it('P8: closes open receipts when the counter is reset, so an old one cannot empty the new count', function () {
    $old = Entitlements::consume($this->anna, 'analyses', 2);

    Entitlements::resetUsage($this->anna, 'analyses');
    Entitlements::consume($this->anna, 'analyses', 10);

    expect(Entitlements::release($this->anna, 'analyses', receipt: $old))->toBeFalse()
        ->and(($this->used)($this->anna))->toBe(10);
});

it('refuses a receipt id that is not an id, rather than shortening it', function () {
    $receipt = Entitlements::consume($this->anna, 'analyses', 1)->toArray();

    expect(fn () => Entitlements::release($this->anna, 'analyses', receipt: array_merge($receipt, ['id' => str_repeat('a', 80)])))
        ->toThrow(InvalidArgumentException::class);
});

it('allows partial releases up to what was booked, and no further', function () {
    $receipt = Entitlements::consume($this->anna, 'analyses', 2);

    expect(Entitlements::release($this->anna, 'analyses', 1, receipt: $receipt))->toBeTrue()
        ->and(Entitlements::release($this->anna, 'analyses', 1, receipt: $receipt))->toBeTrue()
        ->and(Entitlements::release($this->anna, 'analyses', 1, receipt: $receipt))->toBeFalse()
        ->and(($this->used)($this->anna))->toBe(8);
});

it('lets a member give back a booking made at the team, through the member', function () {
    $team = new SubjectReference('team', '7');
    Entitlements::extendSubjects(fn () => [$team]);
    Entitlements::setLimits('chor', ['analyses' => ['value' => 50, 'period' => 'year']]);
    Entitlements::grant($team, 'chor', 'manual');

    $receipt = Entitlements::consume($this->anna, 'analyses', 3);

    expect($receipt->holder()->key())->toBe('team:7')
        ->and(Entitlements::release($this->anna, 'analyses', receipt: $receipt))->toBeTrue()
        ->and(Entitlements::quota($team, 'analyses')->used)->toBe(0);

    app(SubjectExtensions::class)->forget();
});

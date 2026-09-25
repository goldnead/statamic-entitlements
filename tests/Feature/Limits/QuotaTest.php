<?php

use Goldnead\Entitlements\Events\LimitReached;
use Goldnead\Entitlements\Events\UsageConsumed;
use Goldnead\Entitlements\Events\UsageReset;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Limits\Quota;
use Goldnead\Entitlements\Models\Usage;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\Event;

/**
 * Kontingente: Zahlen statt nur Ja/Nein.
 *
 * Die Vorlage ist ChoirLives QuotaService: `active_arrangements_limit` (Bestand,
 * der Aufrufer zählt Projekte) und `analysis_per_year_included` (Verbrauch je
 * Jahr). Hier heissen sie `arrangements` und `analyses`.
 */
beforeEach(function () {
    config()->set('entitlements.limits.keys', [
        'analyses' => ['label' => 'Analysen', 'period' => 'year'],
        'arrangements' => ['label' => 'Arrangements'],
    ]);

    Entitlements::setLimits('solo', ['analyses' => 10, 'arrangements' => 3]);
    Entitlements::setLimits('chor', ['analyses' => 50, 'arrangements' => 25]);
    Entitlements::setLimits('pro', ['analyses' => null, 'arrangements' => null]);

    $this->anna = new SubjectReference('user', '1');
});

it('reads a product limit and knows which kind it is', function () {
    Entitlements::grant($this->anna, 'solo', 'manual');

    $analyses = Entitlements::quota($this->anna, 'analyses');
    $arrangements = Entitlements::quota($this->anna, 'arrangements');

    expect(Entitlements::limit($this->anna, 'analyses'))->toBe(10)
        ->and($analyses->kind)->toBe(Quota::KIND_USAGE)
        ->and($analyses->period)->toBe('year')
        ->and($analyses->holder->key())->toBe('user:1')
        ->and($analyses->source)->toBe(Quota::SOURCE_GRANT)
        ->and($arrangements->kind)->toBe(Quota::KIND_STOCK);
});

it('takes the highest value over several grants, and unlimited above any number', function () {
    Entitlements::grant($this->anna, 'solo', 'manual');
    Entitlements::grant($this->anna, 'chor', 'manual');

    expect(Entitlements::limit($this->anna, 'analyses'))->toBe(50);

    Entitlements::grant($this->anna, 'pro', 'manual');

    expect(Entitlements::limit($this->anna, 'analyses'))->toBeNull()
        ->and(Entitlements::quota($this->anna, 'analyses')->unlimited())->toBeTrue();
});

it('gives nobody anything without a grant, and the fallback product when one is set', function () {
    expect(Entitlements::limit($this->anna, 'analyses'))->toBe(0)
        ->and(Entitlements::quota($this->anna, 'analyses')->source)->toBe(Quota::SOURCE_NONE)
        ->and(Entitlements::consume($this->anna, 'analyses'))->toBeFalse();

    Entitlements::setLimits('free', ['analyses' => 2]);
    config()->set('entitlements.limits.fallback_product', 'free');

    expect(Entitlements::limit($this->anna, 'analyses'))->toBe(2)
        ->and(Entitlements::quota($this->anna, 'analyses')->source)->toBe(Quota::SOURCE_FALLBACK);
});

it('lets a config plan stand and a stored row win over it per key', function () {
    config()->set('entitlements.limits.products.starter', ['analyses' => 5, 'arrangements' => 1]);
    Entitlements::grant($this->anna, 'starter', 'manual');

    expect(Entitlements::limit($this->anna, 'analyses'))->toBe(5);

    Entitlements::setLimits('starter', ['analyses' => 7]);

    expect(Entitlements::limit($this->anna, 'analyses'))->toBe(7)
        ->and(Entitlements::limit($this->anna, 'arrangements'))->toBe(1);
});

it('counts usage, refuses past the limit and announces the limit exactly once', function () {
    Event::fake([UsageConsumed::class, LimitReached::class]);
    Entitlements::setLimits('solo', ['analyses' => 3]);
    Entitlements::grant($this->anna, 'solo', 'manual');

    expect(Entitlements::consume($this->anna, 'analyses'))->toBeTrue()
        ->and(Entitlements::consume($this->anna, 'analyses', 2))->toBeTrue()
        ->and(Entitlements::consume($this->anna, 'analyses'))->toBeFalse()
        ->and(Entitlements::consume($this->anna, 'analyses'))->toBeFalse()
        ->and(Entitlements::remaining($this->anna, 'analyses'))->toBe(0);

    Event::assertDispatchedTimes(UsageConsumed::class, 2);
    Event::assertDispatchedTimes(LimitReached::class, 1);
    Event::assertDispatched(LimitReached::class, fn (LimitReached $e) => $e->limit === 3
        && $e->used === 3
        && $e->key === 'analyses'
        && $e->holder->key() === 'user:1'
        && $e->product === 'solo');
});

it('never books more than the limit in one go', function () {
    Entitlements::setLimits('solo', ['analyses' => 3]);
    Entitlements::grant($this->anna, 'solo', 'manual');

    expect(Entitlements::consume($this->anna, 'analyses', 4))->toBeFalse()
        ->and(Entitlements::consume($this->anna, 'analyses', 2))->toBeTrue()
        ->and(Entitlements::consume($this->anna, 'analyses', 2))->toBeFalse()
        ->and(Entitlements::quota($this->anna, 'analyses')->used)->toBe(2);
});

/**
 * Der deterministische Teil des Rennens: zwei Aufrufer haben beide „noch einer
 * frei" gelesen. Was entscheidet, ist allein das bedingte UPDATE, und das zweite
 * trifft keine Zeile mehr. Das echte Rennen zweier Prozesse gegen MySQL und
 * Postgres steht in ConcurrentConsumeTest.
 */
it('lets only one of two bookings take the last slot, even when both read it as free', function () {
    Entitlements::setLimits('solo', ['analyses' => 1]);
    Entitlements::grant($this->anna, 'solo', 'manual');

    // Both callers read the quota before either books.
    $first = Entitlements::quota($this->anna, 'analyses');
    $second = Entitlements::quota($this->anna, 'analyses');

    expect($first->remaining())->toBe(1)->and($second->remaining())->toBe(1);

    $results = [Entitlements::consume($this->anna, 'analyses'), Entitlements::consume($this->anna, 'analyses')];

    expect($results)->toBe([true, false])
        ->and(Usage::query()->count())->toBe(1)
        ->and(Usage::query()->value('used'))->toBe(1);
});

it('refuses consume() on a stock limit, which the caller counts itself', function () {
    Entitlements::grant($this->anna, 'solo', 'manual');

    Entitlements::consume($this->anna, 'arrangements');
})->throws(LogicException::class);

it('answers a stock limit from the caller\'s count and announces it full once', function () {
    Event::fake([LimitReached::class]);
    Entitlements::grant($this->anna, 'solo', 'manual');

    expect(Entitlements::withinLimit($this->anna, 'arrangements', 1))->toBeTrue()
        ->and(Entitlements::remaining($this->anna, 'arrangements', 1))->toBe(2);

    Event::assertNotDispatched(LimitReached::class);

    // The third arrangement fills the plan: that addition is the moment.
    expect(Entitlements::withinLimit($this->anna, 'arrangements', 2))->toBeTrue();
    Event::assertDispatchedTimes(LimitReached::class, 1);

    // Read without a count: the last count reported, for the Control Panel.
    expect(Entitlements::quota($this->anna, 'arrangements')->used)->toBe(2);

    // Full now; asking again refuses and does not announce again.
    expect(Entitlements::withinLimit($this->anna, 'arrangements', 3))->toBeFalse()
        ->and(Entitlements::withinLimit($this->anna, 'arrangements', 3))->toBeFalse();
    Event::assertDispatchedTimes(LimitReached::class, 1);

    // One deleted, so room again, and the next fill is a new moment.
    expect(Entitlements::withinLimit($this->anna, 'arrangements', 1, 1))->toBeTrue();
    expect(Entitlements::withinLimit($this->anna, 'arrangements', 2))->toBeTrue();
    Event::assertDispatchedTimes(LimitReached::class, 2);
});

it('falls to the next grant when the one with the highest limit expires, and to zero after that', function () {
    Entitlements::grant($this->anna, 'solo', 'manual', expiresAt: now()->addMonths(6));
    Entitlements::grant($this->anna, 'chor', 'manual', expiresAt: now()->addMonth());

    expect(Entitlements::limit($this->anna, 'analyses'))->toBe(50);

    $this->travel(2)->months();

    expect(Entitlements::limit($this->anna, 'analyses'))->toBe(10)
        ->and(Entitlements::quota($this->anna, 'analyses')->product)->toBe('solo');

    $this->travel(6)->months();

    expect(Entitlements::limit($this->anna, 'analyses'))->toBe(0)
        ->and(Entitlements::quota($this->anna, 'analyses')->source)->toBe(Quota::SOURCE_NONE);
});

it('falls when the grant is revoked', function () {
    $chor = Entitlements::grant($this->anna, 'chor', 'manual');
    Entitlements::grant($this->anna, 'solo', 'manual');

    Entitlements::revoke($chor, 'Rückerstattung');

    expect(Entitlements::limit($this->anna, 'analyses'))->toBe(10);
});

it('gives back what a failed job booked, and makes the limit reachable again', function () {
    Event::fake([LimitReached::class]);
    Entitlements::setLimits('solo', ['analyses' => 1]);
    Entitlements::grant($this->anna, 'solo', 'manual');

    Entitlements::consume($this->anna, 'analyses');

    expect(Entitlements::release($this->anna, 'analyses'))->toBeTrue()
        ->and(Entitlements::release($this->anna, 'analyses'))->toBeFalse()
        ->and(Entitlements::remaining($this->anna, 'analyses'))->toBe(1)
        ->and(Entitlements::consume($this->anna, 'analyses'))->toBeTrue();

    Event::assertDispatchedTimes(LimitReached::class, 2);
});

it('resets the counter by hand and says so', function () {
    Event::fake([UsageReset::class]);
    Entitlements::grant($this->anna, 'solo', 'manual');
    Entitlements::consume($this->anna, 'analyses', 4);

    expect(Entitlements::resetUsage($this->anna, 'analyses'))->toBeTrue()
        ->and(Entitlements::remaining($this->anna, 'analyses'))->toBe(10)
        ->and(Entitlements::resetUsage($this->anna, 'analyses'))->toBeFalse();

    Event::assertDispatched(UsageReset::class, fn (UsageReset $e) => $e->previous === 4
        && $e->reason === UsageReset::REASON_MANUAL
        && $e->holder->key() === 'user:1');
});

it('starts a fresh counter when the grant\'s year turns', function () {
    $this->travelTo(now()->setDate(2026, 3, 14)->setTime(10, 0));
    Entitlements::grant($this->anna, 'solo', 'manual');
    Entitlements::consume($this->anna, 'analyses', 10);

    expect(Entitlements::consume($this->anna, 'analyses'))->toBeFalse();

    $quota = Entitlements::quota($this->anna, 'analyses');
    expect($quota->periodStart->format('Y-m-d'))->toBe('2026-03-14')
        ->and($quota->periodEnd->format('Y-m-d'))->toBe('2027-03-14');

    $this->travelTo(now()->setDate(2027, 3, 14)->setTime(12, 0));

    expect(Entitlements::remaining($this->anna, 'analyses'))->toBe(10)
        ->and(Entitlements::consume($this->anna, 'analyses'))->toBeTrue()
        ->and(Usage::query()->count())->toBe(2);
});

it('uses calendar years when told to', function () {
    config()->set('entitlements.limits.period_anchor', 'calendar');
    $this->travelTo(now()->setDate(2026, 3, 14)->setTime(10, 0));
    Entitlements::grant($this->anna, 'solo', 'manual');

    $quota = Entitlements::quota($this->anna, 'analyses');

    // Calendar years in the application timezone (America/Chicago in this
    // suite), stored as UTC.
    expect($quota->periodStart->setTimezone('America/Chicago')->format('Y-m-d H:i'))->toBe('2026-01-01 00:00');
});

it('does not drift at month ends', function () {
    $this->travelTo(now()->setDate(2026, 1, 31)->setTime(9, 0));
    Entitlements::setLimits('monat', ['analyses' => ['value' => 5, 'period' => 'month']]);
    Entitlements::grant($this->anna, 'monat', 'manual');

    $this->travelTo(now()->setDate(2026, 3, 30)->setTime(9, 0));

    $quota = Entitlements::quota($this->anna, 'analyses');

    expect($quota->periodStart->format('Y-m-d'))->toBe('2026-02-28')
        ->and($quota->periodEnd->format('Y-m-d'))->toBe('2026-03-31');
});

it('lists every limit of a subject', function () {
    Entitlements::grant($this->anna, 'solo', 'manual');
    Entitlements::consume($this->anna, 'analyses', 2);

    $quotas = Entitlements::quotasFor($this->anna);

    expect(array_keys($quotas))->toBe(['analyses', 'arrangements'])
        ->and($quotas['analyses']->toArray())->toMatchArray([
            'limit' => 10, 'used' => 2, 'remaining' => 8, 'kind' => 'usage', 'holder' => 'user:1',
        ]);
});

it('keeps counters apart per brand', function () {
    $this->enableMultiBrand();
    $a = $this->makeBrand('alpha');
    $b = $this->makeBrand('beta');

    $context = app('brand-context');

    $context->runFor($a, function () {
        Entitlements::setLimits('solo', ['analyses' => 1]);
        Entitlements::grant($this->anna, 'solo', 'manual');
        Entitlements::consume($this->anna, 'analyses');
    });

    $context->runFor($b, function () {
        expect(Entitlements::limit($this->anna, 'analyses'))->toBe(0);
    });

    $context->runFor($a, function () {
        expect(Entitlements::remaining($this->anna, 'analyses'))->toBe(0);
    });
});

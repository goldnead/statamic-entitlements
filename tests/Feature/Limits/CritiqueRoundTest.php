<?php

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Limits\UsageReceipt;
use Goldnead\Entitlements\Models\Usage;
use Goldnead\Entitlements\Support\SubjectExtensions;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\Log;

/**
 * Nachbesserung nach der Kritik vom 25.09.2026 (Kritik-Punkte 1 bis 5).
 */
beforeEach(function () {
    $this->anna = new SubjectReference('user', '1');
});

afterEach(function () {
    app(SubjectExtensions::class)->forget();
    Entitlements::fallbackUsing(null);
});

// ---------------------------------------------------------------- 1 receipts

it('books a release into the period of the booking, not the one it is released in', function () {
    config()->set('entitlements.limits.period_anchor', 'calendar');
    Entitlements::setLimits('solo', ['analyses' => ['value' => 10, 'period' => 'month']]);

    $this->travelTo(now()->setTimezone('America/Chicago')->setDate(2026, 3, 20)->setTime(12, 0));
    Entitlements::grant($this->anna, 'solo', 'manual');
    $march = Entitlements::consume($this->anna, 'analyses', 2);

    expect($march)->toBeInstanceOf(UsageReceipt::class)
        ->and($march->amount)->toBe(2);

    $this->travelTo(now()->setTimezone('America/Chicago')->setDate(2026, 4, 5)->setTime(12, 0));
    Entitlements::consume($this->anna, 'analyses', 3);

    // The job that was booked in March fails in April.
    expect(Entitlements::release($this->anna, 'analyses', receipt: $march))->toBeTrue();

    $rows = Usage::query()->orderBy('period_key')->pluck('used')->all();

    expect($rows)->toBe([0, 3])
        ->and(Entitlements::quota($this->anna, 'analyses')->used)->toBe(3);
});

it('carries a receipt through a queue as an array', function () {
    Entitlements::setLimits('solo', ['analyses' => ['value' => 10, 'period' => 'year']]);
    Entitlements::grant($this->anna, 'solo', 'manual');

    $receipt = Entitlements::consume($this->anna, 'analyses', 2);
    $stored = json_decode(json_encode($receipt), true);

    expect(UsageReceipt::fromArray($stored))->toEqual($receipt)
        ->and(Entitlements::release($this->anna, 'analyses', receipt: $stored))->toBeTrue()
        ->and(Entitlements::remaining($this->anna, 'analyses'))->toBe(10)
        // Released once; the receipt does not give back twice.
        ->and(Entitlements::release($this->anna, 'analyses', receipt: $stored))->toBeFalse();
});

it('without a receipt releases only within the current period and warns otherwise', function () {
    Log::spy();
    config()->set('entitlements.limits.period_anchor', 'calendar');
    Entitlements::setLimits('solo', ['analyses' => ['value' => 10, 'period' => 'month']]);

    $this->travelTo(now()->setTimezone('America/Chicago')->setDate(2026, 3, 20)->setTime(12, 0));
    Entitlements::grant($this->anna, 'solo', 'manual');
    Entitlements::consume($this->anna, 'analyses', 2);

    $this->travelTo(now()->setTimezone('America/Chicago')->setDate(2026, 4, 5)->setTime(12, 0));

    expect(Entitlements::release($this->anna, 'analyses'))->toBeFalse()
        ->and(Usage::query()->orderBy('period_key')->pluck('used')->all())->toBe([2]);

    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'release'));
});

it('still refuses a booking with a falsy result, so `if (! consume())` keeps working', function () {
    expect(Entitlements::consume($this->anna, 'analyses'))->toBeNull();
});

// ---------------------------------------------------- 2 fallback per subject

it('picks the fallback product per subject type, from config or a callback', function () {
    Entitlements::setLimits('free', ['analyses' => ['value' => 10, 'period' => 'year']]);
    config()->set('entitlements.limits.fallback_products', ['personal_team' => 'free']);

    $personal = new SubjectReference('personal_team', '5');
    $choir = new SubjectReference('team', '6');

    expect(Entitlements::limit($personal, 'analyses'))->toBe(10)
        ->and(Entitlements::limit($choir, 'analyses'))->toBe(0);

    // The callback decides first, and null means none.
    Entitlements::fallbackUsing(fn (SubjectReference $s) => $s->type === 'team' ? 'free' : null);

    expect(Entitlements::limit($choir, 'analyses'))->toBe(10)
        ->and(Entitlements::limit($personal, 'analyses'))->toBe(0);
});

// ------------------------------------------------------------- 3 -1 in config

it('reads -1 from config as unlimited and refuses it in the database', function () {
    config()->set('entitlements.limits.products.legacy', ['analyses' => -1]);
    Entitlements::grant($this->anna, 'legacy', 'manual');

    expect(Entitlements::limit($this->anna, 'analyses'))->toBeNull()
        ->and(Entitlements::quota($this->anna, 'analyses')->unlimited())->toBeTrue();

    Entitlements::setLimits('stored', ['analyses' => -1]);
})->throws(InvalidArgumentException::class);

// --------------------------------------------------- 4 several teams, stock

it('writes a stock counter only for the holder itself, never for a member asking', function () {
    Entitlements::extendSubjects(fn () => [new SubjectReference('team', '7')]);
    Entitlements::setLimits('chor', ['arrangements' => 5]);
    Entitlements::grant(new SubjectReference('team', '7'), 'chor', 'manual');

    // A member's own count is not the team's count.
    expect(Entitlements::withinLimit($this->anna, 'arrangements', 1))->toBeTrue()
        ->and(Usage::query()->count())->toBe(0);

    expect(Entitlements::withinLimit(new SubjectReference('team', '7'), 'arrangements', 3))->toBeTrue()
        ->and(Usage::query()->value('used'))->toBe(3);
});

it('picks the same holder among equally high teams whatever order they come in', function () {
    Entitlements::setLimits('chor', ['analyses' => ['value' => 50, 'period' => 'year']]);
    Entitlements::grant(new SubjectReference('team', '9'), 'chor', 'manual');
    Entitlements::grant(new SubjectReference('team', '10'), 'chor', 'manual');

    Entitlements::extendSubjects(fn () => [new SubjectReference('team', '9'), new SubjectReference('team', '10')]);
    $first = Entitlements::quota($this->anna, 'analyses')->holder->key();

    app(SubjectExtensions::class)->forget();
    Entitlements::extendSubjects(fn () => [new SubjectReference('team', '10'), new SubjectReference('team', '9')]);
    $second = Entitlements::quota($this->anna, 'analyses')->holder->key();

    // Sorted by key: "team:10" < "team:9".
    expect($first)->toBe('team:10')->and($second)->toBe('team:10');
});

// ------------------------------------------------------------- 5 plan change

it('keeps the counter through an upgrade in the middle of the year with a calendar key', function () {
    config()->set('entitlements.limits.keys', ['analyses' => ['period' => 'year', 'anchor' => 'calendar']]);
    Entitlements::setLimits('solo', ['analyses' => 10]);
    Entitlements::setLimits('chor', ['analyses' => 50]);

    $this->travelTo(now()->setTimezone('America/Chicago')->setDate(2026, 2, 1)->setTime(12, 0));
    $solo = Entitlements::grant($this->anna, 'solo', 'manual');
    Entitlements::consume($this->anna, 'analyses', 8);

    $this->travelTo(now()->setTimezone('America/Chicago')->setDate(2026, 6, 15)->setTime(12, 0));
    Entitlements::revoke($solo, 'Upgrade');
    Entitlements::grant($this->anna, 'chor', 'manual');

    $quota = Entitlements::quota($this->anna, 'analyses');

    expect($quota->limit)->toBe(50)
        ->and($quota->used)->toBe(8)
        ->and($quota->remaining())->toBe(42);
});

it('starts a new period on an upgrade with a grant-anchored key, as documented', function () {
    config()->set('entitlements.limits.keys', ['analyses' => ['period' => 'year', 'anchor' => 'grant']]);
    config()->set('entitlements.limits.period_anchor', 'calendar');
    Entitlements::setLimits('solo', ['analyses' => 10]);
    Entitlements::setLimits('chor', ['analyses' => 50]);

    $this->travelTo(now()->setDate(2026, 2, 1)->setTime(12, 0));
    $solo = Entitlements::grant($this->anna, 'solo', 'manual');
    Entitlements::consume($this->anna, 'analyses', 8);

    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(12, 0));
    Entitlements::revoke($solo, 'Upgrade');
    Entitlements::grant($this->anna, 'chor', 'manual');

    // The key overrides the global calendar anchor.
    expect(Entitlements::quota($this->anna, 'analyses')->used)->toBe(0)
        ->and(Entitlements::quota($this->anna, 'analyses')->periodStart->format('m-d'))->toBe('06-15');
});

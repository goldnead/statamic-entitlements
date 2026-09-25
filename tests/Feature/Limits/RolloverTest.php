<?php

use Goldnead\Entitlements\Events\UsageReset;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\Event;

it('announces the end of a period once, and only when something was used', function () {
    $anna = new SubjectReference('user', '1');
    $ben = new SubjectReference('user', '2');

    $this->travelTo(now()->setDate(2026, 3, 14)->setTime(10, 0));
    Entitlements::setLimits('solo', ['analyses' => ['value' => 10, 'period' => 'month']]);
    Entitlements::grant($anna, 'solo', 'manual');
    Entitlements::grant($ben, 'solo', 'manual');

    Entitlements::consume($anna, 'analyses', 4);
    Entitlements::consume($ben, 'analyses');
    Entitlements::release($ben, 'analyses');

    Event::fake([UsageReset::class]);

    $this->artisan('entitlements:announce')->assertSuccessful();
    Event::assertNotDispatched(UsageReset::class);

    $this->travelTo(now()->setDate(2026, 4, 14)->setTime(10, 1));

    $this->artisan('entitlements:announce')->assertSuccessful();
    $this->artisan('entitlements:announce')->assertSuccessful();

    Event::assertDispatchedTimes(UsageReset::class, 1);
    Event::assertDispatched(UsageReset::class, fn (UsageReset $e) => $e->holder->key() === 'user:1'
        && $e->previous === 4
        && $e->reason === UsageReset::REASON_PERIOD
        && $e->periodEnd->format('Y-m-d') === '2026-04-14');
});

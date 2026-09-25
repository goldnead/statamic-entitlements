<?php

use Goldnead\Entitlements\Events\LimitReached;
use Goldnead\Entitlements\Events\UsageConsumed;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Integrations\Automations\AutomationsBridge;
use Goldnead\Entitlements\Integrations\Automations\Triggers\LimitReachedTrigger;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;

it('registers the three limit triggers and leaves the grant triggers to automations', function () {
    expect(app(AutomationsBridge::class)->registered())->toBeTrue();

    $registry = app(TriggerRegistry::class);

    foreach (['entitlements.limit_reached', 'entitlements.usage_consumed', 'entitlements.usage_reset'] as $handle) {
        expect($registry->has($handle))->toBeTrue();
    }

    // Registered by automations itself, not by this addon.
    expect($registry->class('entitlements.granted') ?? '')->not->toStartWith('Goldnead\\Entitlements\\');
});

it('hands a reached limit to the automations dispatcher', function () {
    $spy = new class
    {
        /** @var list<array{0: string, 1: object|array}> */
        public array $calls = [];

        public function dispatch(string $handle, object|array $event): void
        {
            $this->calls[] = [$handle, $event];
        }
    };
    app()->instance(TriggerDispatcher::class, $spy);

    $anna = new SubjectReference('user', '1');
    Entitlements::setLimits('solo', ['analyses' => ['value' => 1, 'period' => 'year']]);
    Entitlements::grant($anna, 'solo', 'manual');

    Entitlements::consume($anna, 'analyses');

    $calls = $spy->calls;
    $handles = array_map(fn ($c) => $c[0], $calls);

    expect($handles)->toContain('entitlements.usage_consumed')
        ->and($handles)->toContain('entitlements.limit_reached');

    $reached = collect($calls)->first(fn ($c) => $c[0] === 'entitlements.limit_reached')[1];
    expect($reached)->toBeInstanceOf(LimitReached::class);
});

it('filters on key and product and builds the context the flow reads', function () {
    $trigger = new LimitReachedTrigger;
    $anna = new SubjectReference('user', '1');
    $event = new LimitReached($anna, new SubjectReference('team', '7'), 'analyses', 50, 50, 'usage', 'chor', 'year');

    expect($trigger->matches($event, []))->toBeTrue()
        ->and($trigger->matches($event, ['limit_key' => 'analyses', 'product' => 'chor']))->toBeTrue()
        ->and($trigger->matches($event, ['limit_key' => 'exports']))->toBeFalse()
        ->and($trigger->matches($event, ['product' => 'solo']))->toBeFalse()
        ->and($trigger->matches(new UsageConsumed($anna, $anna, 'analyses', 1, 1, 5), []))->toBeFalse();

    $context = $trigger->buildContext($event, [])->all();

    expect($context['holder'])->toBe(['type' => 'team', 'id' => '7'])
        ->and($context['subject'])->toBe(['type' => 'user', 'id' => '1'])
        ->and($context['limit']['used'])->toBe(50);
});

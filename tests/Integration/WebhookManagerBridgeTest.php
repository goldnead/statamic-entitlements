<?php

use Goldnead\Entitlements\Events\LimitReached;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Integrations\EventCatalog;
use Goldnead\Entitlements\Integrations\WebhookManager\EntitlementsTrigger;
use Goldnead\Entitlements\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\WebhookManager\Domain\OutboundWebhook\Models\OutboundWebhook;
use Goldnead\WebhookManager\Events\TriggerDetected;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Goldnead\WebhookManager\Jobs\ProcessOutboundDeliveryJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->anna = new SubjectReference('user', '1');
    Entitlements::setLimits('solo', ['analyses' => ['value' => 2, 'period' => 'year']]);
});

function detectedFor(string $handle): TriggerDetected
{
    $found = null;

    Event::assertDispatched(TriggerDetected::class, function (TriggerDetected $d) use ($handle, &$found) {
        if ($d->trigger->triggerHandle === $handle) {
            $found = $d;

            return true;
        }

        return false;
    });

    return $found;
}

it('registers all eight events as triggers', function () {
    expect(app(WebhookManagerBridge::class)->booted())->toBeTrue();

    $registered = array_values(array_filter(
        array_keys(WebhookManager::triggers()->all()),
        fn (string $handle) => str_starts_with($handle, 'entitlements.'),
    ));
    sort($registered);
    $expected = array_keys(EventCatalog::handles());
    sort($expected);

    expect($registered)->toBe($expected)
        ->and(WebhookManager::triggers()->get('entitlements.limit_reached'))->toBeInstanceOf(EntitlementsTrigger::class);

    app()->setLocale('de');
    expect(WebhookManager::triggers()->get('entitlements.limit_reached')->label())->toBe('Entitlements: Grenze erreicht');
});

it('hands a reached limit over with the shared envelope and nothing else', function () {
    Event::fake([TriggerDetected::class]);
    Entitlements::grant($this->anna, 'solo', 'manual');

    Entitlements::consume($this->anna, 'analyses', 2);

    $body = detectedFor('entitlements.limit_reached')->trigger->payload;

    expect(array_keys($body))->toBe(['event', 'event_id', 'occurred_at', 'brand', 'subject_type', 'subject_id', 'holder', 'limit'])
        ->and($body['event'])->toBe('entitlements.limit_reached')
        ->and([$body['subject_type'], $body['subject_id']])->toBe(['user', '1'])
        ->and($body['brand'])->toBe(['id' => 1, 'handle' => 'default'])
        ->and($body['limit'])->toMatchArray(['key' => 'analyses', 'limit' => 2, 'used' => 2, 'remaining' => 0, 'product' => 'solo', 'period' => 'year']);

    detectedFor('entitlements.usage_consumed');
});

it('says what a grant is without its meta', function () {
    Event::fake([TriggerDetected::class]);

    Entitlements::grant($this->anna, 'solo', 'statamic-payments', 'tr_123', meta: ['thanks_token' => 'geheim-token']);

    $body = detectedFor('entitlements.granted')->trigger->payload;

    expect($body['entitlement'])->toMatchArray(['product' => 'solo', 'source' => 'statamic-payments', 'source_ref' => 'tr_123', 'state' => 'active'])
        ->and(json_encode($body))->not->toContain('geheim-token')
        ->and(json_encode($body))->not->toContain('meta');
});

it('queues a delivery for an outbound hook on the event', function () {
    Queue::fake();

    OutboundWebhook::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Grenze',
        'handle' => 'grenze',
        'enabled' => true,
        'trigger_type' => 'entitlements.limit_reached',
        'url' => 'https://example.test/hook',
        'method' => 'POST',
        'payload_type' => 'raw_json',
        'queue_enabled' => true,
    ]);

    Entitlements::grant($this->anna, 'solo', 'manual');
    Entitlements::consume($this->anna, 'analyses', 2);

    Queue::assertPushed(ProcessOutboundDeliveryJob::class);
    expect(DB::table('webhook_deliveries')->where('trigger_type', 'entitlements.limit_reached')->count())->toBe(1);
});

it('sends nothing for a booking that was rolled back', function () {
    $heard = [];
    Event::listen(TriggerDetected::class, function (TriggerDetected $d) use (&$heard) {
        $heard[] = $d->trigger->triggerHandle;
    });

    Entitlements::grant($this->anna, 'solo', 'manual');
    $heard = [];

    try {
        DB::transaction(function () {
            Entitlements::consume($this->anna, 'analyses', 2);

            throw new RuntimeException('zurückgerollt');
        });
    } catch (RuntimeException) {
    }

    expect($heard)->toBe([]);
});

it('registers no second listener on a second boot', function () {
    app(WebhookManagerBridge::class)->boot(app('events'));

    $heard = [];
    Event::listen(TriggerDetected::class, function (TriggerDetected $d) use (&$heard) {
        $heard[] = $d->trigger->triggerHandle;
    });

    LimitReached::dispatch($this->anna, $this->anna, 'analyses', 2, 2, 'usage');

    expect($heard)->toBe(['entitlements.limit_reached']);
});

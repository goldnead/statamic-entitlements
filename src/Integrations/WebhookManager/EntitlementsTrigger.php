<?php

namespace Goldnead\Entitlements\Integrations\WebhookManager;

use Goldnead\Entitlements\Integrations\EventCatalog;
use Goldnead\Entitlements\Integrations\EventPayload;
use Goldnead\WebhookManager\Contracts\TriggerInterface;
use Goldnead\WebhookManager\ValueObjects\TriggerEvent;
use Illuminate\Support\Carbon;

/**
 * One entitlements event as a trigger of the webhook manager.
 *
 * **Loaded only after the bridge has checked that interface by name.** This
 * class `implements` it; touching it on an install without the manager is a
 * fatal "Interface not found" at boot. Only {@see WebhookManagerBridge::boot()}
 * names it.
 */
class EntitlementsTrigger implements TriggerInterface
{
    public function __construct(
        private readonly string $moment,
    ) {}

    public function handle(): string
    {
        return EventCatalog::handle($this->moment);
    }

    /** Asked every time: the CP lists triggers in the viewer's language. */
    public function label(): string
    {
        return (string) __('entitlements::events.'.$this->moment.'.label');
    }

    public function description(): ?string
    {
        $key = 'entitlements::events.'.$this->moment.'.description';
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : null;
    }

    public function sourceType(): string
    {
        return 'entitlements';
    }

    public function build(mixed $source, array $context = []): TriggerEvent
    {
        $at = is_object($source)
            ? Carbon::instance(EventPayload::occurredAt($source))->toImmutable()
            : Carbon::now()->toImmutable();

        $payload = is_object($source) ? EventPayload::build($this->moment, $source) : [
            'event' => $this->handle(),
            'event_id' => sha1($this->handle().'|'.$at->format(\DATE_ATOM)),
            'occurred_at' => $at->format(\DATE_ATOM),
            'brand' => null,
        ];

        return new TriggerEvent(
            triggerHandle: $this->handle(),
            sourceType: $this->sourceType(),
            sourceReference: is_object($source) ? EventPayload::referenceOf($source) : null,
            payload: $payload,
            site: null,
            locale: null,
            isReplay: (bool) ($context['replay'] ?? false),
            eventAt: $at,
        );
    }
}

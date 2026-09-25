<?php

namespace Goldnead\Entitlements\Integrations\Automations\Triggers;

use Goldnead\Entitlements\Integrations\Automations\AutomationsBridge;
use Goldnead\Entitlements\Integrations\EventPayload;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

/**
 * What the three limit triggers share: an optional filter on the limit key and
 * the product, and the same context blocks the webhook body carries.
 *
 * Only loaded when the automations addon is installed — see
 * {@see AutomationsBridge}.
 */
abstract class QuotaTrigger implements AutomationTrigger
{
    /** @return class-string */
    abstract protected static function eventClass(): string;

    abstract protected static function moment(): string;

    public static function handle(): string
    {
        return 'entitlements.'.static::moment();
    }

    public static function label(): string
    {
        return (string) __('entitlements::events.'.static::moment().'.label');
    }

    public static function description(): ?string
    {
        return (string) __('entitlements::events.'.static::moment().'.description');
    }

    public static function group(): string
    {
        return 'Entitlements';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'limit_key',
                'label' => __('entitlements::events.field_key'),
                'type' => 'text',
                'required' => false,
                'help' => __('entitlements::events.field_key_help'),
            ],
            [
                'handle' => 'product',
                'label' => __('entitlements::events.field_product'),
                'type' => 'text',
                'required' => false,
                'help' => __('entitlements::events.field_product_help'),
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'subject' => ['type' => 'string', 'id' => 'string'],
            'holder' => ['type' => 'string', 'id' => 'string'],
            'limit' => [
                'key' => 'string', 'label' => 'string', 'kind' => 'string', 'limit' => 'integer',
                'unlimited' => 'boolean', 'used' => 'integer', 'remaining' => 'integer',
                'product' => 'string', 'period' => 'string', 'period_start' => 'datetime', 'period_end' => 'datetime',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $class = static::eventClass();

        if (! $event instanceof $class) {
            return false;
        }

        $key = trim((string) ($config['limit_key'] ?? ''));
        $product = trim((string) ($config['product'] ?? ''));

        if ($key !== '' && $event->key !== $key) {
            return false;
        }

        if ($product !== '' && (! property_exists($event, 'product') || $event->product !== $product)) {
            return false;
        }

        return true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make(is_object($event) ? EventPayload::context($event) : []);
    }
}

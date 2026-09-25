<?php

namespace Goldnead\Entitlements\Integrations\WebhookManager;

use Goldnead\Entitlements\Integrations\EventCatalog;
use Goldnead\Entitlements\Integrations\EventPayload;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Offers all eight entitlements events to `goldnead/statamic-webhook-manager`
 * as triggers, where that addon is installed.
 *
 * The shape is the suite's (statamic-payments, leadhub): one trigger per
 * moment, a listener per event, re-emitted as the manager's `TriggerDetected`
 * after the commit and in the brand of the event. `registerEventTrigger()` was
 * not taken for the reasons payments gives: it dispatches in whatever brand is
 * current, and fixes the label at boot in one language.
 *
 * **Nothing here loads a class of the webhook manager before its name has been
 * checked as a string.** {@see EntitlementsTrigger} implements the manager's
 * interface and is only named after that check.
 */
class WebhookManagerBridge
{
    public const FACADE = 'Goldnead\\WebhookManager\\Facades\\WebhookManager';

    public const CONTRACT = 'Goldnead\\WebhookManager\\Contracts\\TriggerInterface';

    public const DETECTED = 'Goldnead\\WebhookManager\\Events\\TriggerDetected';

    protected bool $booted = false;

    public static function installed(): bool
    {
        return class_exists(self::FACADE)
            && interface_exists(self::CONTRACT)
            && class_exists(self::DETECTED);
    }

    public static function available(): bool
    {
        return (bool) config('entitlements.bridges.webhook_manager', true) && static::installed();
    }

    public function booted(): bool
    {
        return $this->booted;
    }

    public function boot(Dispatcher $events): void
    {
        if ($this->booted || ! static::available()) {
            return;
        }

        // Bound once the manager's own provider has booted; sibling boot order
        // is not guaranteed. Bail without marking booted so the retry can run.
        if (! app()->bound('webhook-manager')) {
            return;
        }

        $this->booted = true;

        $manager = app('webhook-manager');

        foreach (EventCatalog::MOMENTS as $moment => [$eventClass]) {
            try {
                $manager->registerTrigger(new EntitlementsTrigger($moment));
            } catch (Throwable $e) {
                Log::warning('statamic-entitlements: the webhook manager would not take the trigger ['.EventCatalog::handle($moment).'].', [
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            $events->listen($eventClass, function (object $event) use ($moment): void {
                $this->dispatch($moment, $event);
            });
        }
    }

    /**
     * Never throws: a receiver that is down must cost a webhook, not a grant or
     * a booking. After the commit, never inside it — a rolled-back grant must
     * not have been announced to a third party.
     */
    protected function dispatch(string $moment, object $event): void
    {
        $handle = EventCatalog::handle($moment);

        try {
            DB::afterCommit(fn () => $this->handOver($handle, $event));
        } catch (Throwable $e) {
            Log::warning('statamic-entitlements: an event could not be handed to the webhook manager.', [
                'trigger' => $handle,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    protected function handOver(string $handle, object $event): void
    {
        try {
            $trigger = app('webhook-manager')->triggers()->get($handle);

            if ($trigger === null) {
                return;
            }

            $detected = self::DETECTED;
            $send = fn () => event(new $detected($trigger->build($event)));
            $brand = EventPayload::brandIdOf($event);

            if ($brand === null) {
                $send();

                return;
            }

            app('brand-context')->runFor($brand, $send);
        } catch (Throwable $e) {
            Log::warning('statamic-entitlements: an event could not be handed to the webhook manager.', [
                'trigger' => $handle,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}

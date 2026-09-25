<?php

namespace Goldnead\Entitlements\Integrations\Automations;

use Goldnead\Entitlements\Events\LimitReached;
use Goldnead\Entitlements\Events\UsageConsumed;
use Goldnead\Entitlements\Events\UsageReset;
use Goldnead\Entitlements\Integrations\Automations\Triggers\LimitReachedTrigger;
use Goldnead\Entitlements\Integrations\Automations\Triggers\UsageConsumedTrigger;
use Goldnead\Entitlements\Integrations\Automations\Triggers\UsageResetTrigger;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registers the three limit events as automation triggers and hands them to
 * the automations addon's own dispatcher.
 *
 * The five grant events are not registered here: statamic-automations ships
 * triggers for them under the same `entitlements.*` handles and listens to
 * them itself. Registering them twice would start every automation twice.
 *
 * Both halves are guarded by `class_exists` on the sibling's classes as
 * strings; the trigger classes `implements` its contract and are only named
 * after that check.
 */
class AutomationsBridge
{
    public const FACADE = 'Goldnead\\StatamicAutomations\\Facades\\Automations';

    public const DISPATCHER = 'Goldnead\\StatamicAutomations\\Engine\\TriggerDispatcher';

    public const CONTRACT = 'Goldnead\\StatamicAutomations\\Contracts\\AutomationTrigger';

    /** @var array<class-string, string> event class => trigger class (as strings until checked) */
    public const TRIGGERS = [
        LimitReached::class => LimitReachedTrigger::class,
        UsageConsumed::class => UsageConsumedTrigger::class,
        UsageReset::class => UsageResetTrigger::class,
    ];

    protected bool $registered = false;

    public static function installed(): bool
    {
        return class_exists(self::FACADE)
            && class_exists(self::DISPATCHER)
            && interface_exists(self::CONTRACT);
    }

    public static function available(): bool
    {
        return (bool) config('entitlements.bridges.automations', true) && static::installed();
    }

    public function registered(): bool
    {
        return $this->registered;
    }

    /** Idempotent: the booted callbacks fire more than once. */
    public function register(Dispatcher $events): void
    {
        if ($this->registered || ! static::available()) {
            return;
        }

        // Bound once the sibling's provider has registered. Bail without
        // marking registered, so the retry at the end of the booted queue can.
        if (! app()->bound('automations')) {
            return;
        }

        try {
            $facade = self::FACADE;
            $root = $facade::getFacadeRoot();

            // Asked of the object, never of the facade: a facade forwards
            // through __callStatic and declares nothing it forwards.
            if (! is_object($root) || ! method_exists($root, 'registerTrigger')) {
                return;
            }

            foreach (self::TRIGGERS as $eventClass => $triggerClass) {
                $root->registerTrigger($triggerClass);

                $events->listen($eventClass, function (object $event) use ($triggerClass): void {
                    $this->dispatch($triggerClass::handle(), $event);
                });
            }

            $this->registered = true;
        } catch (Throwable $e) {
            Log::warning('statamic-entitlements: the automation triggers could not be registered.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Start every enabled automation that begins on this handle, through the
     * sibling's own dispatcher, after the commit. Never throws: an automation
     * that fails must not cost the booking.
     */
    public function dispatch(string $handle, object $event): void
    {
        try {
            DB::afterCommit(function () use ($handle, $event): void {
                try {
                    app(self::DISPATCHER)->dispatch($handle, $event);
                } catch (Throwable $e) {
                    Log::warning('statamic-entitlements: dispatching the automation trigger failed.', [
                        'trigger' => $handle,
                        'exception' => $e->getMessage(),
                    ]);
                }
            });
        } catch (Throwable $e) {
            Log::warning('statamic-entitlements: dispatching the automation trigger failed.', [
                'trigger' => $handle,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}

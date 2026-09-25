<?php

namespace Goldnead\Entitlements\Tests;

use Goldnead\EmailTemplates\EmailTemplatesServiceProvider;
use Goldnead\Entitlements\Integrations\Automations\AutomationsBridge;
use Goldnead\Entitlements\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\StatamicAutomations\ServiceProvider as AutomationsServiceProvider;
use Goldnead\WebhookManager\Events\TriggerDetected;
use Goldnead\WebhookManager\WebhookManagerServiceProvider;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;

/**
 * The bed with the real Webhook Manager and the real automations addon booted.
 *
 * Both are dev dependencies. A stand-in would prove only that the bridges call
 * what the stand-in offers; this proves the siblings take the triggers and
 * dispatch them. The install without either is proved in a separate process
 * ({@see Unit\BootWithoutSiblingsTest}).
 *
 * Testbench has no booted phase in which Statamic runs the addons'
 * `bootAddon()`, so the parts of the siblings' boot that matter for dispatch
 * are invoked by hand, and the bridges get the retry production gives them.
 */
abstract class SiblingTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            WebhookManagerServiceProvider::class,
            AutomationsServiceProvider::class,
            EmailTemplatesServiceProvider::class,
        ]);
    }

    /**
     * The bridges stay off while the app boots: the manager's `bootBindings()`
     * below replaces its trigger registry, and a registration made before
     * that would land in the discarded one. Switched on once the siblings are
     * wired, which is the order a Statamic boot produces.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('entitlements.bridges.webhook_manager', false);
        $app['config']->set('entitlements.bridges.automations', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('entitlements.bridges.webhook_manager', true);
        config()->set('entitlements.bridges.automations', true);

        foreach ([WebhookManagerServiceProvider::class, AutomationsServiceProvider::class] as $provider) {
            $this->loadMigrationsFrom(dirname((new ReflectionClass($provider))->getFileName(), 2).'/database/migrations');
        }

        $this->artisan('migrate')->run();

        // On MySQL the siblings' DDL just ended RefreshDatabase's transaction
        // implicitly while Laravel still counts it open; a test that opens its
        // own transaction would then ask for a savepoint that does not exist.
        $pdo = DB::connection()->getPdo();

        if (DB::transactionLevel() > 0 && ! $pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $manager = $this->app->getProvider(WebhookManagerServiceProvider::class);

        // `bootEvents` only when the manager's TriggerDetected listener is not
        // there yet. Whether its provider's boot wired it depends on Statamic's
        // addon manifest in the testbench app: locally it did (wiring it again
        // delivered twice), in CI it did not (nothing was delivered).
        $methods = ['bootWebhookConfig', 'bootBindings', 'bootRegistries'];

        if (! $this->app['events']->hasListeners(TriggerDetected::class)) {
            $methods[] = 'bootEvents';
        }

        foreach ($methods as $method) {
            if (method_exists($manager, $method)) {
                (new ReflectionMethod($manager, $method))->invoke($manager);
            }
        }

        $events = $this->app->make('events');
        $this->app->make(WebhookManagerBridge::class)->boot($events);
        $this->app->make(AutomationsBridge::class)->register($events);
    }
}

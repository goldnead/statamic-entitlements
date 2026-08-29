<?php

namespace Goldnead\Entitlements;

use Goldnead\Entitlements\Bridges\ActivityBridge;
use Goldnead\Entitlements\Contracts\PackageResolver;
use Goldnead\Entitlements\Contracts\SubjectResolver;
use Goldnead\Entitlements\Integrations\Insights\Active;
use Goldnead\Entitlements\Integrations\Insights\Expired;
use Goldnead\Entitlements\Integrations\Insights\Granted;
use Goldnead\Entitlements\Integrations\Insights\Revoked;
use Goldnead\Entitlements\Query\Scopes\Filters;
use Goldnead\Entitlements\Support\MorphSubjectResolver;
use Goldnead\Entitlements\Support\NullPackageResolver;
use Goldnead\Entitlements\Support\SourceRegistry;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Query\Scopes\Scope;
use Statamic\Statamic;
use Throwable;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    /**
     * The Control Panel bundle.
     *
     * Statamic 6 reads this from the provider property and *only* from there —
     * `extra.statamic.vite` in composer.json is not consulted, and an addon that
     * declares its build there ships a Control Panel with no addon assets at
     * all. The three values must byte-match `laravel()` in vite.config.js.
     *
     * Untyped on purpose: the parent declares it without a type and PHP refuses
     * a child that narrows one.
     *
     * @phpstan-ignore-next-line property.defaultValue
     */
    protected $vite = [
        'hotFile' => __DIR__.'/../resources/dist/hot',
        'publicDirectory' => 'resources/dist',
        'input' => ['resources/js/cp.js'],
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/entitlements.php', 'entitlements');

        // The two extension points, bound to null objects so an install that
        // never touches them behaves as if bundles and custom subjects did not
        // exist. `bind` rather than `singleton`: a consumer's resolver may hold
        // request state, and forcing it to be shared would be this package
        // deciding that for them.
        $this->app->bind(SubjectResolver::class, MorphSubjectResolver::class);
        $this->app->bind(PackageResolver::class, NullPackageResolver::class);

        $this->app->singleton(SourceRegistry::class);

        // NOT bound under a short slug. A container key named after the addon is
        // how a sibling package overwrote Laravel's own `events` dispatcher; the
        // FQCN cannot collide with anything. The alias exists for Laravel's
        // container to resolve the facade quickly and is prefixed for the same
        // reason.
        $this->app->singleton(EntitlementManager::class);
        $this->app->alias(EntitlementManager::class, 'statamic-entitlements');

        // Registered against the resolving translator rather than in boot: the
        // nav and permission labels are built before bootAddon() runs.
        $langPath = __DIR__.'/../resources/lang';

        $this->app->resolving('translator', fn ($translator) => $translator->addNamespace('entitlements', $langPath));

        if ($this->app->resolved('translator')) {
            $this->app['translator']->addNamespace('entitlements', $langPath);
        }
    }

    public function bootAddon(): void
    {
        $this->bootMigrations()
            ->bootCommands()
            ->bootFilterScopes()
            ->bootNavigation()
            ->bootPermissions()
            ->bootActivityBridge()
            ->bootInsightsMetrics()
            ->bootPublishables();
    }

    protected function bootMigrations(): self
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        return $this;
    }

    /**
     * `src/Commands` is autoloaded by core, but only after Statamic's own boot
     * sequence — which never fires in a plain console context, which is the only
     * context this command ever runs in. Registering it here is what makes
     * `php artisan entitlements:announce` exist on a cron box.
     */
    protected function bootCommands(): self
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Console\Commands\AnnounceStateTransitions::class]);
        }

        return $this;
    }

    /**
     * The listing filters.
     *
     * They live in `src/Query/Scopes/Filters/`, which core autoloads, so no
     * `$scopes` property is declared — an explicit list goes stale the moment
     * somebody adds a class, which surfaces as "my filter does not show up".
     *
     * They are still registered here because core's own scope pass runs only
     * after Statamic's boot sequence has fired, which never happens in a plain
     * console or test context, so `Scope::filters()` would return nothing there.
     * `Scope::register()` is idempotent, so core repeating it later costs
     * nothing. CpFilterScopeTest pins the list.
     */
    protected function bootFilterScopes(): self
    {
        foreach (self::LISTING_FILTERS as $scope) {
            $scope::register();
        }

        return $this;
    }

    /** @var list<class-string<Scope>> */
    public const LISTING_FILTERS = [
        Filters\State::class,
        Filters\Source::class,
        Filters\Product::class,
    ];

    protected function bootNavigation(): self
    {
        if (! config('entitlements.cp.enabled', true)) {
            return $this;
        }

        Nav::extend(function ($nav): void {
            $nav->create(__('entitlements::cp.nav'))
                ->section('Users')
                // A name from Statamic's own icon set, not a pasted SVG. Registering
                // the nav item is also what earns the addon its breadcrumbs.
                ->icon('key')
                ->route('entitlements.index')
                ->can('view entitlements');
        });

        return $this;
    }

    /**
     * Three permissions, because there are three genuinely different things to
     * permit.
     *
     * Reading who has access to what is a support task. Handing out access is a
     * commercial decision. Taking it away is the one that generates a refund
     * request and an angry email, and it is the one the source system gated
     * behind the same blanket `access-admin` as everything else — meaning
     * anybody who could open the Control Panel could do all three.
     *
     * `grant` and `revoke` are siblings under `view` rather than nested in each
     * other: a support role that may grant a replacement licence has no business
     * revoking one, and the reverse is equally true.
     */
    protected function bootPermissions(): self
    {
        Permission::extend(function (): void {
            Permission::group('entitlements', __('entitlements::cp.nav'), function (): void {
                Permission::register('view entitlements')
                    ->label(__('entitlements::cp.permission_view'))
                    ->children([
                        Permission::make('grant entitlements')
                            ->label(__('entitlements::cp.permission_grant')),
                        Permission::make('revoke entitlements')
                            ->label(__('entitlements::cp.permission_revoke')),
                    ]);
            });
        });

        return $this;
    }

    /**
     * Attaches the optional activity bridge.
     *
     * Three call sites for one attachment, and the repetition is the point.
     * Statamic invokes `bootAddon()` from inside a `Statamic::booted()` callback
     * during the application's boot phase; a nested `$app->booted()` written
     * there fires *immediately* rather than later, because
     * `Application::booted()` runs its callback at once when the app is already
     * booted. So there is no single moment that is reliably late enough.
     *
     * `ActivityBridge::attach()` is idempotent and never records a negative
     * answer, which makes the retries free: the first call that finds the
     * sibling wins and the rest return at the first line.
     */
    protected function bootActivityBridge(): self
    {
        if (ActivityBridge::attach($this->app)) {
            return $this;
        }

        $this->app->booted(fn () => ActivityBridge::attach($this->app));
        Statamic::booted(fn () => ActivityBridge::attach($this->app));

        return $this;
    }

    /**
     * The metric handles this addon contributes, and the classes behind them.
     *
     * Handle and class both, so the registry can file the class name without
     * building anything to find out what it is called — an installation with
     * twenty addons would otherwise construct every metric of every one of them
     * on a request that renders none.
     *
     * **The handles are frozen from the moment they are registered.** They end
     * up in saved dashboards and in URLs; renaming one is a breaking change.
     *
     * @var array<class-string, string>
     */
    protected const INSIGHTS_METRICS = [
        Granted::class => 'entitlements.granted',
        Revoked::class => 'entitlements.revoked',
        Expired::class => 'entitlements.expired',
        Active::class => 'entitlements.active',
    ];

    /**
     * Offer the four figures to the analytics addon, if it is there.
     *
     * Three call sites for one registration, for the same reason the activity
     * bridge has three: Statamic invokes `bootAddon()` from inside a
     * `Statamic::booted()` callback, and a nested `$app->booted()` written there
     * fires *immediately* rather than later, because `Application::booted()`
     * runs its callback at once when the app is already booted. So there is no
     * single moment that is reliably late enough, and registering too early
     * registers into nothing — an empty screen with no error anywhere, which is
     * the worst shape this failure could take.
     *
     * {@see attachInsights()} is idempotent, so the retries cost nothing.
     *
     * **Nothing here throws, ever.** A missing, half-installed or mid-upgrade
     * analytics addon must cost a few tiles on a screen nobody has open, never
     * an access decision. The guards are the three that have each caught a real
     * variation of "installed but not quite": the facade class may be absent,
     * the container may refuse to build the manager, and an older release of the
     * sibling may carry the facade without this method on it.
     *
     * The metric classes name the sibling's contract in their `extends` and
     * their type hints, which is safe precisely because of the first guard: PHP
     * loads a class when something touches it, and nothing touches these unless
     * the facade exists. Hence `suggest` in composer.json rather than `require`.
     */
    protected function bootInsightsMetrics(): self
    {
        if ($this->attachInsights()) {
            return $this;
        }

        $this->app->booted(fn () => $this->attachInsights());
        Statamic::booted(fn () => $this->attachInsights());

        return $this;
    }

    /** Whether the metrics are now registered. Safe to call any number of times. */
    protected function attachInsights(): bool
    {
        if ($this->insightsRegistered) {
            return true;
        }

        $facade = '\Goldnead\StatamicInsights\Facades\Insights';

        if (! class_exists($facade)) {
            return false;
        }

        try {
            $manager = $facade::getFacadeRoot();

            // Asked of the object, never of the facade: a facade forwards
            // through `__callStatic` and declares none of what it forwards, so
            // the probe on the facade class itself is always false. That is how
            // a whole set of bridges in this family silently did nothing.
            if (! is_object($manager) || ! method_exists($manager, 'registerMetric')) {
                return false;
            }

            foreach (self::INSIGHTS_METRICS as $class => $handle) {
                $manager->registerMetric($class, $handle);
            }

            $this->insightsRegistered = true;
        } catch (Throwable $e) {
            Log::warning('statamic-entitlements: the insights metrics could not be registered.', [
                'exception' => $e->getMessage(),
            ]);
        }

        return $this->insightsRegistered;
    }

    /** Set once the metrics have been handed over, so the retries stay free. */
    protected bool $insightsRegistered = false;

    protected function bootPublishables(): self
    {
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'entitlements-migrations');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/entitlements'),
        ], 'entitlements-translations');

        return $this;
    }
}

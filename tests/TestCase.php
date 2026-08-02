<?php

namespace Goldnead\Entitlements\Tests;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Entitlements\Bridges\ActivityBridge;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;
use Statamic\Facades\Stache;
use Statamic\Licensing\Outpost;
use Statamic\Providers\StatamicServiceProvider;
use Statamic\Statamic;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Testbench never fires Statamic::booted callbacks — AddonServiceProvider::boot()
        // returns early because this package is not in the testbench app's addon
        // manifest — so bootAddon(), which loads the migrations, filters, nav,
        // permissions and the activity bridge, has to be invoked by hand.
        $this->app->getProvider(ServiceProvider::class)?->bootAddon();

        $this->artisan('migrate')->run();

        // The CP layout pulls Statamic's own Vite bundle, which is not published
        // into the testbench app. The markup is what we assert on.
        $this->withoutVite();

        $this->giveTestbenchAComposerLock();
        $this->forgetUsersLeftBehindByOtherTests();

        app('brand-context')->forget();
        ActivityBridge::forget();
    }

    protected function getPackageProviders($app): array
    {
        return [
            StatamicServiceProvider::class,
            // The CP is an Inertia app and its exception handler asks the request
            // whether it is an Inertia visit. Without the provider the macro is
            // missing and every error page throws instead of rendering, which
            // turns an expected 404 into a 500.
            \Inertia\ServiceProvider::class,
            \Goldnead\BrandContext\ServiceProvider::class,
            \Goldnead\IdentityContracts\ServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Statamic' => Statamic::class,
            'Entitlements' => Entitlements::class,
            'BrandContext' => BrandContext::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->testingConnection());

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.url', 'https://example.test');

        // Neither UTC nor any zone the tests use. Every timestamp this package
        // stores is UTC and every one of them decides access; with
        // app.timezone=UTC a correct conversion and a missing one look identical.
        $app['config']->set('app.timezone', 'America/Chicago');

        // File-backed users: this package has no opinion on where users live, and
        // the CP route tests only need somebody the Gate recognises.
        $app['config']->set('statamic.users.repository', 'file');

        // The `statamic.cp` group contains ContactOutpost, which phones Statamic's
        // licensing service on every Control Panel request. In a suite that means
        // seconds per test waiting for a network call nothing here depends on —
        // and a suite that only passes with internet access is not a suite.
        $app->singleton(Outpost::class, fn () => new class extends Outpost
        {
            public function __construct() {}

            public function radio() {}

            public function response()
            {
                return [];
            }
        });

        $app['config']->set('brand-context.multi_brand', false);

        // The forms render core's own `PublishForm` page, which lives in
        // statamic/cms's Vue sources rather than in the testbench app's view
        // paths. Inertia's page-existence check cannot find it there and would
        // fail an assertion about a page core ships and resolves at runtime.
        $app['config']->set('inertia.testing.ensure_pages_exist', false);
        $app['config']->set('queue.default', 'sync');
    }

    /**
     * In-memory SQLite by default, so the suite keeps running anywhere with no
     * setup. Set `DB_DRIVER=mysql` to point the identical suite at a real MySQL
     * server instead — see phpunit.mysql.xml.
     *
     * SQLite is not a substitute for that run, and for this package less than for
     * most: the guarantee it makes about duplicate grants is enforced by a unique
     * index, and everything that can make such an index quietly useless —
     * InnoDB's key-length limit, utf8mb4 byte arithmetic, prefix comparison under
     * a case-insensitive collation — exists only on the real engine.
     *
     * @return array<string, mixed>
     */
    protected function testingConnection(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'entitlements_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }

    protected function defineRoutes($router): void
    {
        // The real mount puts these inside core's own authenticated CP group
        // (routes/cp.php `Statamic::additionalCpRoutes()`), so the bed does too.
        // Mounting them under a bare `web` group instead would mean an anonymous
        // request reached the controller and was refused there — which passes,
        // but proves nothing about the redirect a real visitor gets.
        $router->middleware(['statamic.cp', 'statamic.cp.authenticated'])
            ->prefix('cp')
            ->name('statamic.cp.')
            ->group(__DIR__.'/../routes/cp.php');

        $this->mountStandInSiblingRoutes($router);
    }

    /**
     * Stand-ins for the routes of a sibling addon installed next to this one.
     *
     * They belong to the bed rather than to the test that reads them because a
     * sibling registers its routes the same way this package does: at boot, and
     * therefore ahead of Statamic's `{segments?}` frontend catch-all. A route
     * added from inside a test body is shadowed by that catch-all and answers 404
     * no matter what the bindings do, which would make the check pass for the
     * wrong reason.
     *
     * Each one does nothing but echo its own parameter. If this package ever
     * binds a name they use, the echo stops happening: the binder resolves the
     * value against a repository here first, finds nothing, and aborts 404 —
     * precisely what a sibling's delete button did.
     *
     * @see Feature\RouteParameterCollisionTest
     */
    protected function mountStandInSiblingRoutes($router): void
    {
        $router->middleware(SubstituteBindings::class)
            ->group(function ($router) {
                foreach (static::NAMES_A_SIBLING_MIGHT_USE as $name) {
                    $router->get(
                        'sibling-probe/'.$name.'/{'.$name.'}',
                        fn ($value) => (string) $value
                    );
                }
            });
    }

    /**
     * Generic names a sibling addon could plausibly put in one of its own routes.
     * `entitlement` and `subject` are on the list because this package uses one
     * and talks about the other, and the point of the list is that using a name
     * is not the same as claiming it.
     *
     * @var list<string>
     */
    public const NAMES_A_SIBLING_MIGHT_USE = [
        'entitlement', 'subject', 'grant', 'product', 'contact', 'automation',
        'rule', 'template', 'webhook', 'handle', 'id', 'slug', 'record',
    ];

    /**
     * Statamic's CP layout resolves its own version from `base_path('composer.lock')`
     * and throws without one. The testbench app has no lock file of its own, so
     * lend it ours — otherwise no CP view can be rendered in a test at all.
     */
    protected function giveTestbenchAComposerLock(): void
    {
        $target = base_path('composer.lock');

        if (file_exists($target)) {
            return;
        }

        $source = __DIR__.'/../composer.lock';

        if (file_exists($source)) {
            @copy($source, $target);
        }
    }

    /**
     * The file user repository writes into the testbench app, which lives in
     * vendor/ and survives both the test and the run. Two tests that each save a
     * user leave two on disk, and the third request into the CP dies on "Statamic
     * Pro is required for multiple users" — a failure with nothing to do with the
     * test that hits it.
     */
    protected function forgetUsersLeftBehindByOtherTests(): void
    {
        $directory = config('statamic.stache.stores.users.directory');

        if ($directory && is_dir($directory)) {
            foreach (glob(rtrim($directory, '/').'/*.yaml') ?: [] as $file) {
                @unlink($file);
            }
        }

        Stache::store('users')->clear();
    }

    protected function enableMultiBrand(): void
    {
        config()->set('brand-context.multi_brand', true);
        app('brand-context')->forget();
    }

    protected function makeBrand(string $handle): Brand
    {
        return Brand::create(['handle' => $handle, 'name' => ucfirst($handle)]);
    }

    protected function runningAgainstMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
}

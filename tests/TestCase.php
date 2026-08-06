<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\Numerosis\Testing\InteractsWithTenantPanel;
use Nvade\Numerosis\Tests\Support\CloneTenantSchema;
use Orchestra\Testbench\TestCase as Orchestra;
use PDO;
use Workbench\App\Providers\Filament\AdminPanelProvider;
use Workbench\App\Providers\Filament\TenantAdminPanelProvider;

abstract class TestCase extends Orchestra
{
    use InteractsWithTenantPanel;

    /**
     * The two Filament panel providers are Workbench-only stand-ins for
     * what a real host (thin-app, Phase 7/8) will register — see their own
     * class docblocks. They belong here, not in NumerosisServiceProvider,
     * for the same reason `getEnvironmentSetUp()` below hand-sets
     * `config('tenancy.*')` etc: the package never owns a panel.
     */
    protected function getPackageProviders($app): array
    {
        return [
            NumerosisServiceProvider::class,
            AdminPanelProvider::class,
            TenantAdminPanelProvider::class,
        ];
    }

    /**
     * Testbench's `ignorePackageDiscoveriesFrom()` defaults to `['*']` unless
     * either this is overridden or the test case composes `WithWorkbench` —
     * without one of those, `PackageManifest::getManifest()` rejects every
     * vendor package's discovered providers *and* aliases, silently, no
     * error at boot. Every vendor dependency this package relies on
     * (`livewire/livewire`'s `Livewire` facade alias and `livewire.finder`
     * binding, `filament/filament`'s facades, …) is reached through Laravel's
     * own auto-discovery, exactly like a real consuming app — nothing here
     * hand-registers a provider or alias that discovery already supplies, so
     * this one override is the fix, not a per-package alias list.
     */
    public function ignorePackageDiscoveriesFrom(): array
    {
        return [];
    }

    /**
     * Everything `docs/host-requirements.md` says a host must own. The
     * package's own suite has to *be* that host for its tests: none of
     * `config/{tenancy,database,auth,session,filesystems,permission}.php`
     * ship from the package (`mergeConfigFrom` merges one level deep, so a
     * package-owned `tenancy.php` would silently drop whatever bootstrapper
     * a real consumer appends — see that doc's `config/tenancy.php` row),
     * so nothing here is a shortcut; it is the same shape a real thin-app
     * install would set, pointed at the Workbench stub models under
     * `workbench/app/Models` instead of a real host's `App\Models`.
     *
     * `DOMAIN`/`CENTRAL_SUBDOMAIN` are set via `putenv()`, not
     * `$app['config']->set()`, because `config/numerosis.php`'s
     * `domains.tenant_pattern` and its `billing`/`tenancy` siblings compute
     * their defaults with `env()` *inside the config file*, at
     * `mergeConfigFrom()` time — after this method returns but before any
     * test runs. Setting the process env here, before that merge happens,
     * is what makes `env('DOMAIN')` resolve inside those files at all.
     */
    protected function getEnvironmentSetUp($app): void
    {
        putenv('DOMAIN=numerosistest.test');
        putenv('CENTRAL_SUBDOMAIN=central');
        putenv('SESSION_DOMAIN=.numerosistest.test');
        $_ENV['DOMAIN'] = 'numerosistest.test';
        $_ENV['CENTRAL_SUBDOMAIN'] = 'central';
        $_ENV['SESSION_DOMAIN'] = '.numerosistest.test';

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        // These three used to be hand-set as `app.domain`, `app.host` and
        // `app.central.*` — keys this package invented inside Laravel's own
        // config/app.php, which is exactly why the harness had to supply them
        // at all: a framework config file cannot receive a package default
        // through mergeConfigFrom(). They are `numerosis.domains.*` now and
        // carry real defaults derived from APP_URL, so this block only
        // overrides them to the harness's own hostname rather than rescuing
        // the package from a NULL.
        $app['config']->set('numerosis.domains.apex', 'numerosistest.test');
        $app['config']->set('numerosis.domains.central', 'central.numerosistest.test');
        $app['config']->set('numerosis.domains.tenant_pattern', '{tenant}.numerosistest.test');

        // Numerosis::routes() only binds routes/web.php to the Host header
        // matching a configured central domain (Route::domain($domain), one
        // group per entry in tenancy.central_domains). A relative-URL
        // request ($this->postJson('billing/webhook')) has to resolve
        // against that same host, or it 404s on a route that is, in fact,
        // registered — reads like a routing bug, is a harness default.
        //
        // Setting config('app.url') alone does not fix this: Testbench's
        // `SetRequestForConsole` bootstrapper binds a default Request with
        // Host 'localhost' into the container *before* this method runs,
        // and Illuminate\Routing\UrlGenerator prefers that bound request's
        // root over config('app.url') whenever one is already bound —
        // url()/route()/prepareUrlForRequest() (what postJson() uses to
        // build its request URL) all silently keep resolving 'localhost'
        // regardless of the config value. URL::forceRootUrl() is the
        // documented override for exactly this: it wins over the bound
        // request unconditionally.
        $app['config']->set('app.url', 'http://central.numerosistest.test');
        \Illuminate\Support\Facades\URL::forceRootUrl('http://central.numerosistest.test');

        // Config key itself, not just the PDO init string — LockWaitTimeoutTest
        // asserts the two agree via Config::integer('database.lock_wait_timeout'),
        // same key saas-m's own config/database.php exposes at the top level.
        $lockWaitTimeout = 10;
        $app['config']->set('database.lock_wait_timeout', $lockWaitTimeout);

        $mysqlOptions = extension_loaded('pdo_mysql') ? [
            (PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND) => "SET SESSION lock_wait_timeout = {$lockWaitTimeout}, innodb_lock_wait_timeout = {$lockWaitTimeout}",
        ] : [];

        $mysql = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'testing',
            'username' => 'root',
            'password' => 'root',
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => $mysqlOptions,
        ];

        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql', $mysql);
        $app['config']->set('database.connections.central', $mysql);
        $app['config']->set('database.connections.tenant', $mysql);

        $app['config']->set('tenancy.tenant_model', \App\Models\Central\Tenant::class);
        $app['config']->set('tenancy.id_generator', \Stancl\Tenancy\UUIDGenerator::class);
        $app['config']->set('tenancy.domain_model', \App\Models\Central\Domain::class);
        $app['config']->set('tenancy.central_user_model', \App\Models\Central\CentralUser::class);
        $app['config']->set('tenancy.tenant_user_model', \App\Models\Tenant\User::class);
        $app['config']->set('tenancy.central_domains', ['central.numerosistest.test']);
        $app['config']->set('tenancy.bootstrappers', [
            \Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
            \Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,
            \Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
            \Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
            \Nvade\Numerosis\Services\Tenancy\Bootstrappers\SpatiePermissionsBootstrapper::class,
            \Nvade\Numerosis\Services\Tenancy\Bootstrappers\AuthGuardBootstrapper::class,
        ]);
        $app['config']->set('tenancy.database.central_connection', 'central');
        $app['config']->set('tenancy.database.prefix', 'tenant');
        $app['config']->set('tenancy.database.suffix', '');
        $app['config']->set('tenancy.filesystem.suffix_base', 'tenant');
        $app['config']->set('tenancy.filesystem.disks', ['local', 'public']);
        $app['config']->set('tenancy.filesystem.root_override', [
            'local' => '%storage_path%/app/private/',
            'public' => '%storage_path%/app/public/',
        ]);
        $app['config']->set('tenancy.filesystem.suffix_storage_path', true);
        $app['config']->set('tenancy.cache.tag_base', 'tenant');
        $app['config']->set('tenancy.migration_parameters', [
            '--force' => true,
            '--path' => [Numerosis::tenantMigrationPath()],
            '--realpath' => true,
        ]);
        $app['config']->set('tenancy.seeder_parameters', [
            '--class' => \Nvade\Numerosis\Database\Seeders\TenantDatabaseSeeder::class,
        ]);

        // Every test in this suite creates models via the Workbench stubs
        // (App\Models\Central\Tenant etc, not the package's own concrete
        // classes) — the exact host shape D8/D12's config-first
        // Numerosis::model() was designed for. Without this, package code
        // that writes a class-string through Numerosis::model() (e.g.
        // LinkSubscriptionToTenant's subscribable_type) disagrees with the
        // class actually used to create the row, and a polymorphic lookup
        // that filters on that column silently finds nothing.
        $app['config']->set('numerosis.models', [
            Tenant::class => \App\Models\Central\Tenant::class,
            \Nvade\Numerosis\Models\Central\Domain::class => \App\Models\Central\Domain::class,
            \Nvade\Numerosis\Models\Central\CentralUser::class => \App\Models\Central\CentralUser::class,
            \Nvade\Numerosis\Models\Central\Subscription::class => \App\Models\Central\Subscription::class,
            \Nvade\Numerosis\Models\Central\PaymentPlan::class => \App\Models\Central\PaymentPlan::class,
            \Nvade\Numerosis\Models\Central\PendingTenantProvision::class => \App\Models\Central\PendingTenantProvision::class,
            \Nvade\Numerosis\Models\Tenant\Invitation::class => \App\Models\Tenant\Invitation::class,
            \Nvade\Numerosis\Models\Tenant\Module::class => \App\Models\Tenant\Module::class,
            \Nvade\Numerosis\Models\Tenant\User::class => \App\Models\Tenant\User::class,
        ]);

        $app['config']->set('auth.defaults.guards.context.central', 'web');
        $app['config']->set('auth.defaults.guards.context.tenant', 'tenant');
        $app['config']->set('auth.guards.web', ['driver' => 'session', 'provider' => 'central_users']);
        $app['config']->set('auth.guards.tenant', ['driver' => 'session', 'provider' => 'tenant_users']);
        $app['config']->set('auth.providers.central_users', [
            'driver' => 'eloquent',
            'model' => \App\Models\Central\CentralUser::class,
        ]);
        $app['config']->set('auth.providers.tenant_users', [
            'driver' => 'eloquent',
            'model' => \App\Models\Tenant\User::class,
        ]);
        // Two host-owned files, deliberately disagreeing: config/auth.php's
        // `social.providers` is button metadata for five providers, while
        // config/services.php carries credentials for only two of them.
        // `Support\Social\ConfiguredProviders` is the intersection, and
        // SocialLoginButtonsTest asserts exactly that — a `github` button
        // must not render off metadata alone. Setting `providers` to `[]`
        // (what this was) made every socialite test either see no button or
        // reach Socialite with no credentials, which surfaces as
        // `Missing required configuration keys [client_id, client_secret,
        // redirect] for [Laravel\Socialite\Two\GoogleProvider]` from the
        // redirect route rather than as missing config.
        $app['config']->set('auth.social.providers', [
            'google' => ['label' => 'Google', 'hover' => '', 'icon' => 'heroicon-o-globe-alt'],
            'github' => ['label' => 'GitHub', 'hover' => '', 'icon' => 'heroicon-o-code-bracket'],
            'discord' => ['label' => 'Discord', 'hover' => '', 'icon' => 'heroicon-o-chat-bubble-left-right'],
            'facebook' => ['label' => 'Facebook', 'hover' => '', 'icon' => 'heroicon-o-globe-alt'],
            'gitlab' => ['label' => 'GitLab', 'hover' => '', 'icon' => 'heroicon-o-code-bracket'],
        ]);

        // The button component resolves its href as
        // route(config('auth.social.routes.redirect.name')) — with the key
        // unset that is route(null), i.e. `Route [] not defined` from a view,
        // which names neither the config key nor the route.
        $app['config']->set('auth.social.routes', [
            'login' => ['name' => 'oauth.callback'],
            'redirect' => ['name' => 'oauth'],
        ]);

        foreach (['google', 'discord'] as $driver) {
            $app['config']->set("services.{$driver}", [
                'client_id' => "{$driver}-test-client-id",
                'client_secret' => "{$driver}-test-client-secret",
                'redirect' => "http://central.numerosistest.test/oauth/{$driver}/callback",
            ]);
        }

        // Password::sendResetLink() resolves its user model through the
        // 'passwords' broker config, not through 'providers' directly —
        // without this, ForgotPassword/ResetPassword fall back to Laravel's
        // own generic Illuminate\Foundation\Auth\User (no Notifiable trait),
        // which surfaces as "Call to undefined method ...User::notify()"
        // rather than a config-missing error.
        $app['config']->set('auth.defaults.passwords', 'users');
        $app['config']->set('auth.passwords.users', [
            'provider' => 'central_users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ]);

        $app['config']->set('session.domain', '.numerosistest.test');
        $app['config']->set('session.driver', 'array');

        // Testbench's skeleton .env (vendor/orchestra/testbench-core/laravel/.env)
        // sets CACHE_STORE=database, matching modern Laravel's own skeleton
        // default — but `database/migrations/central/2026_01_07_195854_remove_
        // redundant_tables.php` deliberately drops the `cache`/`cache_locks`
        // tables that store needs, on the assumption a real host runs Redis in
        // production (see .claude/rules/exception-handling.md's `failed_jobs`
        // bullet for the sibling case). Left unset, every write through the
        // default cache store — including CentralUserObserver's
        // ForgetsCacheKey — throws `Base table or view not found: 1146 …
        // 'cache' doesn't exist`, which reads like a broken migration rather
        // than a harness pinned to the wrong store. `session.driver` above
        // gets the same treatment for the same reason.
        $app['config']->set('cache.default', 'array');

        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ]);
        $app['config']->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => '/storage',
            'visibility' => 'public',
            'throw' => false,
        ]);
        $app['config']->set('filesystems.disks.livewire', [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'throw' => false,
        ]);
        $app['config']->set('livewire.temporary_file_upload.disk', 'livewire');

        // Livewire's own default config already points 'pages'/'layouts' at
        // resource_path('views/{pages,layouts}') — correct for a plain
        // Laravel app, wrong here, since those files ship from the package
        // (routes/{web,tenant}.php's `Route::livewire('...', 'pages::...')`,
        // and resources/views/layouts/app/header.blade.php's
        // `<livewire:layouts::header />`). See docs/host-requirements.md's
        // `config/livewire.php` row.
        $app['config']->set('livewire.component_namespaces', [
            'layouts' => dirname(__DIR__).'/resources/views/layouts',
            'pages' => dirname(__DIR__).'/resources/views/pages',
        ]);

        $app['config']->set('permission.models.permission', \Nvade\Numerosis\Models\Permission::class);
        $app['config']->set('permission.models.role', \Nvade\Numerosis\Models\Role::class);
        $app['config']->set('permission.column_names.model_morph_key', 'model_id');
        $app['config']->set('permission.table_names', [
            'roles' => 'roles',
            'permissions' => 'permissions',
            'model_has_permissions' => 'model_has_permissions',
            'model_has_roles' => 'model_has_roles',
            'role_has_permissions' => 'role_has_permissions',
        ]);
        $app['config']->set('permission.cache.store', 'array');

        $app['config']->set('cashier.model', \App\Models\Central\Tenant::class);
        $app['config']->set('cashier.key', 'pk_test_dummy');
        $app['config']->set('cashier.secret', 'sk_test_dummy');
        $app['config']->set('cashier.currency', 'usd');

        $app['config']->set('queue.default', 'sync');

        // Gated by QUEUE_FAILED_DRIVER, not by queue.default — see
        // .claude/rules/exception-handling.md. The package ships the central
        // `failed_jobs` migration, so the host has to point this at a
        // connection that carries it; Testbench's skeleton points at sqlite,
        // and the failure is `Database file at path […]/database.sqlite does
        // not exist`, which names neither this key nor failed_jobs.
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'mysql',
            'table' => 'failed_jobs',
        ]);
        $app['config']->set('mail.default', 'array');

        // spatie/laravel-activitylog: not a "suggest" in composer.json terms
        // despite the config name — Tenant\User and Tenant\Invitation compose
        // LogsActivity unconditionally (see composer.json's "require" list),
        // so its config/migration must exist regardless of ActivityLogFeature
        // (which only gates the Filament UI on top of it).
        $app['config']->set('activitylog.database_connection', null);
        $app['config']->set('activitylog.table_name', 'activity_log');
        $app['config']->set('activitylog.activity_model', \Spatie\Activitylog\Models\Activity::class);
        $app['config']->set('activitylog.default_log_name', 'default');
        $app['config']->set('activitylog.default_auth_driver', null);
        $app['config']->set('activitylog.subject_returns_soft_deleted_models', false);
        $app['config']->set('activitylog.enabled', true);

        $this->stubViteManifest($app);
    }

    /**
     * The package ships Vite *sources* only (resources/css, resources/js) —
     * the host owns the build (Phase 9 / docs/host-requirements.md). The
     * Workbench harness has no build step of its own, so every view that
     * hits @vite() throws ViteManifestNotFoundException instead of exercising
     * whatever the test actually cares about. Writing a fake manifest is
     * correct here specifically because tests never fetch the referenced
     * assets — they render server-side HTML and assert against that, so a
     * manifest entry only needs to resolve to *some* file path, never a real
     * built one.
     */
    private function stubViteManifest(\Illuminate\Foundation\Application $app): void
    {
        $buildDir = $app->publicPath('build');

        if (! is_dir($buildDir)) {
            mkdir($buildDir, 0755, true);
        }

        $entries = ['resources/css/app.css', 'resources/js/app.js', 'resources/js/central.js', 'resources/js/tenant.js'];
        $manifest = [];

        foreach ($entries as $entry) {
            $manifest[$entry] = [
                'file' => 'assets/'.basename($entry),
                'src' => $entry,
                'isEntry' => true,
            ];
        }

        file_put_contents($buildDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * A real host loads these via `Numerosis::routes()` from
     * `bootstrap/app.php`'s `withRouting(using: ...)` — Testbench has no
     * such hook, but `defineRoutes()` is the same "run once before every
     * test" moment. Without this, every named route the package ships
     * (`login`, `home`, `features`, `checkout.subscription`, …) is
     * unresolvable and any test asserting or generating one fails with
     * `Route [...] not defined`, which reads like a missing feature rather
     * than a harness gap.
     *
     * `Numerosis::routes()` registers a `Route::middleware('tenant')` group
     * (see `routes/tenant.php`'s consumer), but the aliases/groups that name
     * resolves to are registered by `Numerosis::middleware()` — a separate
     * method taking `Illuminate\Foundation\Configuration\Middleware`, the
     * config object `bootstrap/app.php`'s `withMiddleware()` hands a real
     * host. Testbench never constructs one, so this replicates that method's
     * body directly against the router instead — same "the package never
     * owns a panel" reasoning `getPackageProviders()`'s docblock gives for
     * the Workbench panel providers. Skipping this doesn't fail at route
     * *registration* time (`Route::middleware('tenant')` just stores the
     * group name), it fails the moment a *request* hits a tenant route and
     * the router tries to resolve `'tenant'` as a middleware class:
     * `BindingResolutionException: Target class [tenant] does not exist.`
     */
    protected function defineRoutes($router): void
    {
        $router->aliasMiddleware('invitation.status', \Nvade\Numerosis\Http\Middleware\CheckInvitationStatus::class);
        $router->aliasMiddleware('tenancy.identification', \Nvade\Numerosis\Providers\TenancyServiceProvider::TENANCY_IDENTIFICATION);
        $router->aliasMiddleware('tenancy.route', \Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains::class);
        $router->aliasMiddleware('tenancy.session', \Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant::class);

        $router->middlewareGroup('tenant', [
            'web',
            'tenancy.identification',
            'tenancy.route',
            'tenancy.session',
        ]);
        $router->middlewareGroup('universal', []);

        Numerosis::routes();
    }

    /**
     * Throwaway connection teardown drops tenant databases through.
     *
     * @see deleteTenantDatabases()
     */
    private const MAINTENANCE_CONNECTION = 'tenant_teardown';

    /**
     * Central tables this test has written to, so teardown clears exactly those.
     *
     * @var array<string, true>
     */
    private array $dirtyCentralTables = [];

    /**
     * Build a tenant subdomain the same way the app does — via
     * `config('numerosis.domains.tenant_pattern')` — rather than a hardcoded
     * `.nvade.dev` fixture, so tests stay correct if `DOMAIN`/`CENTRAL_SUBDOMAIN`
     * ever change.
     */
    protected function tenantDomain(string $id): string
    {
        return str_replace('{tenant}', $id, Config::string('numerosis.domains.tenant_pattern'));
    }

    protected function setUp(): void
    {
        // Registered *before* parent::setUp(), which is what makes this run
        // *after* RefreshDatabase's rollback — the opposite of how it reads.
        //
        // Testbench's beforeApplicationDestroyed() is `array_unshift`
        // (Orchestra\Testbench\Concerns\ApplicationTestingHooks), where
        // Illuminate\Foundation\Testing\TestCase's is `[] =`. Under Testbench
        // the callbacks therefore run last-registered-first, so registering
        // after parent::setUp() — the way saas-m does, correctly, on plain
        // Laravel — puts this cleanup *ahead* of the rollback that
        // RefreshDatabase registers during parent::setUp().
        //
        // That inversion is what produced the `Unknown database 'tenantX'`
        // bucket: deleteTenantDatabases() dropped the tenant database, then
        // RefreshDatabase's own callback called $connection->getPdo() on the
        // still-current tenant connection (tenancy is still initialized at
        // teardown, so the default connection *is* the tenant one) and PDO
        // reconnected to a schema that no longer existed. The test body had
        // already passed; only teardown threw, and Testbench swallows all but
        // the first callback exception, which is why it surfaced as a bare
        // PDOException at `parent::tearDown()` with no test-side frame.
        //
        // The array is only reset in tearDownTheApplicationTestingHooks(),
        // after the callbacks run, and beforeApplicationDestroyed() itself
        // touches nothing but that property — so calling it before the
        // application exists is safe.
        //
        // deleteCentralWrites: central and default point at the same database,
        // so deleting while that transaction still holds its row locks blocks
        // for the full innodb_lock_wait_timeout.
        //
        // deleteTenantDatabases: a test that ends inside tenant context leaves
        // the default connection pointed at the tenant database, so dropping it
        // first makes the rollback reconnect to a database that no longer
        // exists and throw `Unknown database`.
        $this->beforeApplicationDestroyed(function (): void {
            // Each step gets its own finally, because the exception these
            // guards exist for (the lock-wait timeout this whole file is
            // about) is thrown by deleteCentralWrites() — the *first* step.
            // Sharing one try block therefore skipped exactly the cleanup
            // that matters most: deleteTenantDatabases() never ran on the
            // failing tests, leaking a physical database per occurrence.
            // disconnectAllConnections() still runs last regardless, so a
            // test that fails here does not hand its own stranded
            // transaction to the next one.
            try {
                try {
                    try {
                        $this->deleteCentralWrites();
                    } finally {
                        $this->deleteTenantDatabases();
                    }
                } finally {
                    $this->disconnectAllConnections();
                }
            } finally {
                Features::forceForTesting(null);
            }
        });

        parent::setUp();

        $this->recordCentralWrites();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->keepSchema();

        // TurnstileFeature::$forcedForTesting is a plain static, not
        // container-scoped, so a test that calls forceForTesting() would
        // otherwise leak its override into whichever test runs next in the
        // same process — same class of trap as Tenant::unsetEventDispatcher()
        // (see .claude/rules/testing.md).
        TurnstileFeature::forceForTesting(null);
    }

    /**
     * Stop RefreshDatabase from scheduling a `migrate:fresh` for the next test.
     *
     * RefreshDatabase resets its migrated flag when a test's transaction is gone
     * by teardown:
     *
     *     if ($connection->getPdo() && ! $connection->getPdo()->inTransaction()) {
     *         RefreshDatabaseState::$migrated = false;
     *     }
     *
     * Every test that bootstraps tenancy trips it. stancl's
     * DatabaseTenancyBootstrapper purges the default connection when it switches
     * to the tenant database, so `getPdo()` returns a fresh session that was
     * never in the transaction. The next test then pays a full rebuild of the
     * central schema — about 3s, on a suite where most tests touch tenancy.
     *
     * No test issues DDL against that schema, so the rebuild only ever restores
     * what is already there. Rows a lost transaction committed are the real
     * consequence, and deleteCentralWrites() handles those.
     */
    private function keepSchema(): void
    {
        RefreshDatabaseState::$migrated = true;
    }

    /**
     * Note every table written on the `central` connection.
     *
     * RefreshDatabase only transacts the default connection, so nothing rolls
     * these back — see deleteCentralWrites(). The listener sits on the event
     * dispatcher rather than on the connection so it survives the DB::purge()
     * calls that tenancy and this class make.
     */
    private function recordCentralWrites(): void
    {
        $central = Config::string('tenancy.database.central_connection', 'central');

        DB::listen(function (QueryExecuted $query) use ($central): void {
            if ($query->connectionName !== $central) {
                return;
            }

            if (preg_match('/^\s*(?:insert(?:\s+ignore)?\s+into|replace\s+into|update)\s+`?([\w-]+)`?/i', $query->sql, $matches) !== 1) {
                return;
            }

            $this->dirtyCentralTables[$matches[1]] = true;
        });
    }

    /**
     * Undo the writes RefreshDatabase cannot.
     *
     * Models using stancl's CentralConnection trait (Tenant,
     * PendingTenantProvision) and anything else resolving the `central`
     * connection write on a session RefreshDatabase never opened a transaction
     * on, so their rows survive into the next test and collide on unique keys —
     * `users.email`, `subscriptions.stripe_id`, `tenants.id`.
     *
     * Transacting `central` too is not an option: stancl's MySQLDatabaseManager
     * issues its `CREATE DATABASE` on that connection, and MySQL implicitly
     * commits on DDL, so any test creating a tenant would lose the transaction
     * mid-test anyway.
     *
     * The list is recorded rather than hardcoded so a new central-connection
     * model needs no change here.
     */
    private function deleteCentralWrites(): void
    {
        if (! $this->app || $this->dirtyCentralTables === []) {
            return;
        }

        $connection = DB::connection(Config::string('tenancy.database.central_connection', 'central'));

        // Deleting in write order would mean tracking dependencies between the
        // tables; the rows are all going regardless.
        $connection->statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (array_keys($this->dirtyCentralTables) as $table) {
                $connection->table($table)->delete();
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS = 1');

            $this->dirtyCentralTables = [];
        }
    }

    /**
     * QUEUE_CONNECTION=sync in testing means creating a Tenant model runs the
     * TenantCreated pipeline (CreateDatabase/MigrateDatabase/SeedTenantDatabase)
     * synchronously, provisioning a real physical database. RefreshDatabase
     * only rolls back the central `tenants` row inside a transaction — the
     * CREATE DATABASE statement is DDL and survives that rollback — so without
     * this, every test that creates a Tenant leaves an orphaned database behind.
     *
     * The DROP is issued directly rather than relying on the TenantDeleted ->
     * DeleteDatabase listener: Tenant::unsetEventDispatcher() is static, so a
     * single test calling it silences model events for every later test in the
     * process, and their databases would then never be dropped.
     *
     * It must not go through the default connection. MySQL implicitly commits
     * on DDL, so a `DROP DATABASE` there ends the RefreshDatabase transaction
     * and commits everything the test wrote, leaving rows that collide with the
     * next test.
     *
     * The table check is pinned to the central connection for the same reason:
     * a test that ends inside tenant context leaves the default connection
     * pointed at the tenant database, where `tenants` does not exist — so an
     * unpinned check reads false and every such test leaks its database.
     */
    private function deleteTenantDatabases(): void
    {
        $central = Config::string('tenancy.database.central_connection', 'central');

        if (! $this->app || ! Schema::connection($central)->hasTable('tenants')) {
            return;
        }

        $tenantClass = $this->tenantModelClass();

        $tenants = $tenantClass::query()->get();

        $tenantClass::query()->delete();

        // Databases the clone helper made are known by name, so the common case
        // costs nothing.
        $databases = CloneTenantSchema::takeCreatedDatabases();

        // Tenants created outside the clone path — tests that fake the queue and
        // migrate by hand, or that swap the pipeline back — are not recorded, so
        // fall back to their derived names. Still no INFORMATION_SCHEMA scan.
        $prefix = Config::string('tenancy.database.prefix', 'tenant');

        foreach ($tenants as $tenant) {
            $databases[] = $prefix.$tenant->getTenantKey();
        }

        // Never the template: it is built once per process, and dropping it here
        // would make the next test rebuild it, which is the cost this avoids.
        $template = CloneTenantSchema::templateDatabase();

        $databases = array_filter(
            array_unique($databases),
            fn (string $database): bool => $database !== $template,
        );

        if ($databases === []) {
            return;
        }

        $connection = $this->maintenanceConnection();

        foreach ($databases as $database) {
            $name = str_replace('`', '``', $database);

            $connection->statement("DROP DATABASE IF EXISTS `{$name}`");
        }

        DB::purge(self::MAINTENANCE_CONNECTION);
    }

    /**
     * Closes every named connection's PDO object at the end of each test.
     *
     * `DatabaseTenancyBootstrapper` purges (discards) the default connection's
     * PDO object whenever `$tenant->run()` switches context, mid-test, with no
     * guaranteed COMMIT/ROLLBACK on the connection being replaced. If that
     * connection was inside RefreshDatabase's open transaction — true of any
     * test that writes through the default connection before calling
     * `$tenant->run()` — the abandoned PDO object's underlying MySQL session
     * is never closed by Laravel, so it never triggers the server-side
     * auto-rollback a clean disconnect would. It just sits there as an idle
     * (`Sleep`) connection, still holding whatever row/table locks its last
     * statement took, for the rest of the process — this is the actual
     * mechanism behind the "stranded transaction" in the lock-wait-timeout
     * failures documented below and in `.claude/rules/testing.md`.
     *
     * `RefreshDatabase`'s own rollback does not fix this: it calls rollback on
     * whichever PDO object the connection resolver holds *now*, not on the one
     * that got orphaned mid-test. Explicitly purging every connection name
     * here forces PHP to drop the last reference to each PDO object, which
     * closes the socket and lets MySQL roll back and free the locks itself —
     * whether or not Laravel's own transaction-depth bookkeeping ever ran a
     * ROLLBACK statement against it.
     */
    private function disconnectAllConnections(): void
    {
        if (! $this->app) {
            return;
        }

        foreach (['mysql', 'central', 'tenant'] as $name) {
            DB::purge($name);
        }
    }

    /**
     * A connection with its own PDO session, so the DDL above cannot commit a
     * transaction any other connection is holding.
     */
    private function maintenanceConnection(): Connection
    {
        $central = Config::string('tenancy.database.central_connection', 'central');

        /** @var array<string, mixed> $config */
        $config = config("database.connections.{$central}");

        config(['database.connections.'.self::MAINTENANCE_CONNECTION => $config]);

        DB::purge(self::MAINTENANCE_CONNECTION);

        return DB::connection(self::MAINTENANCE_CONNECTION);
    }

    /**
     * `Nvade\Numerosis\Models\Central\Tenant` is `abstract` (see
     * `.claude/plans/package-extraction.md` Phase 4.4) — a call written as
     * `Tenant::query()` still compiles, but late static binding resolves
     * `static` to the literal class the call was written against, so
     * `new static` inside Eloquent's own `query()`/`forceCreate()` tries to
     * instantiate the abstract class itself and throws. Every static call
     * must go through the *configured* concrete class instead, matching how
     * a real request resolves `config('tenancy.tenant_model')`.
     *
     * @return class-string<Tenant>
     */
    private function tenantModelClass(): string
    {
        /** @var class-string<Tenant> $class */
        $class = Config::string('tenancy.tenant_model');

        return $class;
    }
}

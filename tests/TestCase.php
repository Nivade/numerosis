<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests;

use App\Models\Central\CentralUser;
use App\Models\Central\Domain;
use App\Models\Tenant\User;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Nvade\Numerosis\Database\Seeders\TenantDatabaseSeeder;
use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Models\Role;
use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\Numerosis\Services\Tenancy\Bootstrappers\AuthGuardBootstrapper;
use Nvade\Numerosis\Services\Tenancy\Bootstrappers\SpatiePermissionsBootstrapper;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\Numerosis\Support\Tenancy\TenancyConfigKeys;
use Nvade\Numerosis\Support\Tenancy\TenancyVersion;
use Nvade\Numerosis\Testing\CleansUpTenancyDatabases;
use Nvade\Numerosis\Tests\Support\CloneTenantSchema;
use Nvade\NumerosisFilament\Testing\InteractsWithTenantPanel;
use Orchestra\Testbench\TestCase as Orchestra;
use Pdo\Mysql;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;

abstract class TestCase extends Orchestra
{
    use CleansUpTenancyDatabases;
    use InteractsWithTenantPanel;

    /**
     * `NumerosisServiceProvider::registerFilamentPanels()` registers both
     * panels itself now (Phase 2, package-host-bootstrap) — Workbench used
     * to carry its own stand-in copies of `AdminPanelProvider`/
     * `TenantAdminPanelProvider` for exactly what the package now supplies
     * by default, and registering both would have silently double-registered
     * the same panel ids (Filament's `PanelRegistry` keys by id and the
     * second registration just overwrites the first — no error, no signal).
     */
    protected function getPackageProviders($app): array
    {
        return [
            NumerosisServiceProvider::class,
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

        $app->make(Repository::class)->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        // These three used to be hand-set as `app.domain`, `app.host` and
        // `app.central.*` — keys this package invented inside Laravel's own
        // config/app.php, which is exactly why the harness had to supply them
        // at all: a framework config file cannot receive a package default
        // through mergeConfigFrom(). They are `numerosis.domains.*` now and
        // carry real defaults derived from APP_URL, so this block only
        // overrides them to the harness's own hostname rather than rescuing
        // the package from a NULL.
        $app->make(Repository::class)->set('numerosis.domains.apex', 'numerosistest.test');
        $app->make(Repository::class)->set('numerosis.domains.central', 'central.numerosistest.test');
        $app->make(Repository::class)->set('numerosis.domains.tenant_pattern', '{tenant}.numerosistest.test');

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
        $app->make(Repository::class)->set('app.url', 'http://central.numerosistest.test');
        URL::forceRootUrl('http://central.numerosistest.test');

        // Config key itself, not just the PDO init string — LockWaitTimeoutTest
        // asserts the two agree via Config::integer('database.lock_wait_timeout'),
        // same key saas-m's own config/database.php exposes at the top level.
        $lockWaitTimeout = 10;
        $app->make(Repository::class)->set('database.lock_wait_timeout', $lockWaitTimeout);

        $mysqlOptions = extension_loaded('pdo_mysql') ? [
            Mysql::ATTR_INIT_COMMAND => "SET SESSION lock_wait_timeout = {$lockWaitTimeout}, innodb_lock_wait_timeout = {$lockWaitTimeout}",
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

        $app->make(Repository::class)->set('database.default', 'mysql');
        $app->make(Repository::class)->set('database.connections.mysql', $mysql);
        $app->make(Repository::class)->set('database.connections.central', $mysql);
        $app->make(Repository::class)->set('database.connections.tenant', $mysql);

        // Every key this block sets is one HostConfig::apply() would also
        // set, given the chance — but it doesn't get the chance here.
        // Testbench's own boot order (CreatesApplication::
        // resolveApplicationBootstrappers()) runs RegisterProviders — which
        // is what fires NumerosisServiceProvider::packageRegistered(), and
        // therefore HostConfig::apply() — *before* getEnvironmentSetUp()
        // runs. A real host's config files are loaded by LoadConfiguration,
        // long before any provider registers, so HostConfig sees the real
        // values there; here, it runs first and only ever sees Testbench's
        // and stancl's own stock defaults, several steps before this
        // method's putenv()/Config::set() calls exist for it to read.
        // Tried deleting this block on the assumption HostConfig would
        // backfill it (matching the numerosis.models.* proof Phase 4 could
        // make safely — that one resolves lazily, at the moment a test
        // calls Numerosis::model(), long after this method has already
        // run); it does not hold for anything HostConfig computes from
        // config this method itself sets (database.default,
        // numerosis.domains.central, …) — HostConfig ran too early to see
        // any of it, and cloned/derived from Testbench's own stock values
        // instead (a sqlite :memory: 'central' connection, an empty
        // tenancy.central_domains, a bogus stock tenant-migration path).
        // 319 of 526 tests failed. Restored, with this note so the same
        // experiment isn't repeated the same way — see
        // .claude/rules/testing.md for the recorded version.
        TenancyConfigKeys::set('tenant_model', \App\Models\Central\Tenant::class);
        TenancyConfigKeys::set('id_generator', TenancyVersion::uuidGeneratorClass());
        TenancyConfigKeys::set('domain_model', Domain::class);
        $app->make(Repository::class)->set('tenancy.central_user_model', CentralUser::class);
        $app->make(Repository::class)->set('tenancy.tenant_user_model', User::class);
        TenancyConfigKeys::set('central_domains', ['central.numerosistest.test']);
        $app->make(Repository::class)->set('tenancy.bootstrappers', [
            DatabaseTenancyBootstrapper::class,
            CacheTenancyBootstrapper::class,
            FilesystemTenancyBootstrapper::class,
            QueueTenancyBootstrapper::class,
            SpatiePermissionsBootstrapper::class,
            AuthGuardBootstrapper::class,
        ]);
        $app->make(Repository::class)->set('tenancy.database.central_connection', 'central');
        $app->make(Repository::class)->set('tenancy.database.prefix', 'tenant');
        $app->make(Repository::class)->set('tenancy.database.suffix', '');
        $app->make(Repository::class)->set('tenancy.filesystem.suffix_base', 'tenant');
        $app->make(Repository::class)->set('tenancy.filesystem.disks', ['local', 'public']);
        $app->make(Repository::class)->set('tenancy.filesystem.root_override', [
            'local' => '%storage_path%/app/private/',
            'public' => '%storage_path%/app/public/',
        ]);
        $app->make(Repository::class)->set('tenancy.filesystem.suffix_storage_path', true);
        $app->make(Repository::class)->set('tenancy.cache.tag_base', 'tenant');
        $app->make(Repository::class)->set('tenancy.migration_parameters', [
            '--force' => true,
            '--path' => [Numerosis::tenantMigrationPath()],
            '--realpath' => true,
        ]);
        $app->make(Repository::class)->set('tenancy.seeder_parameters', [
            '--class' => TenantDatabaseSeeder::class,
        ]);

        // No explicit numerosis.models.* here (unlike before Phase 4 of
        // better-dx.md): every test in this suite creates models via the
        // Workbench stubs (App\Models\Central\Tenant etc), which sit at
        // exactly the path Numerosis::model()'s convention step now checks
        // (App\Models\<suffix>) and extend the package model it resolves —
        // so they're picked up automatically, no config needed. This is the
        // live proof that the convention fallback works: if it stopped
        // resolving these stubs, every test touching
        // LinkSubscriptionToTenant's subscribable_type (or any other
        // class-string Numerosis::model() writes) would fail immediately.

        // numerosis.auth.guards.{central,tenant} already default to 'web'/
        // 'tenant' (config/numerosis.php), matching the guard names below —
        // nothing to override here.
        $app->make(Repository::class)->set('auth.guards.web', ['driver' => 'session', 'provider' => 'central_users']);
        $app->make(Repository::class)->set('auth.guards.tenant', ['driver' => 'session', 'provider' => 'tenant_users']);
        $app->make(Repository::class)->set('auth.providers.central_users', [
            'driver' => 'eloquent',
            'model' => CentralUser::class,
        ]);
        $app->make(Repository::class)->set('auth.providers.tenant_users', [
            'driver' => 'eloquent',
            'model' => User::class,
        ]);
        // numerosis.social.providers/routes (config/numerosis.php) already
        // carry this same five-provider metadata and the oauth/oauth.callback
        // route names — nothing to override here. `Support\Social\
        // ConfiguredProviders` intersects that list against config('services')
        // credentials, so SocialLoginButtonsTest's "no client id, no button"
        // assertion still depends only on the services.* block below.
        foreach (['google', 'discord'] as $driver) {
            $app->make(Repository::class)->set("services.{$driver}", [
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
        $app->make(Repository::class)->set('auth.defaults.passwords', 'users');
        $app->make(Repository::class)->set('auth.passwords.users', [
            'provider' => 'central_users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ]);

        $app->make(Repository::class)->set('session.domain', '.numerosistest.test');
        $app->make(Repository::class)->set('session.driver', 'array');

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
        $app->make(Repository::class)->set('cache.default', 'array');

        // dev-master only: CacheTenancyBootstrapper::getCacheStores() throws
        // ("Cache store [array] is not supported by this bootstrapper.") the
        // moment it tries to scope a cache-backed session whose driver is
        // `array` — v3's equivalent bootstrapper has no such check. Every
        // test entering tenant context hits this, since `session.driver`
        // above is always `array` here. No key on v3 (harmless no-op there,
        // see .claude/rules/stancl-tenancy-v4.md); this package's tests
        // exercise tenant *cache* scoping directly, never session scoping,
        // so turning it off costs nothing.
        $app->make(Repository::class)->set('tenancy.cache.scope_sessions', false);

        $app->make(Repository::class)->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ]);
        $app->make(Repository::class)->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => '/storage',
            'visibility' => 'public',
            'throw' => false,
        ]);
        $app->make(Repository::class)->set('filesystems.disks.livewire', [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'throw' => false,
        ]);
        $app->make(Repository::class)->set('livewire.temporary_file_upload.disk', 'livewire');

        // Livewire's own default config already points 'pages'/'layouts' at
        // resource_path('views/{pages,layouts}') — correct for a plain
        // Laravel app, wrong here, since those files ship from the package
        // (routes/{web,tenant}.php's `Route::livewire('...', 'pages::...')`,
        // and resources/views/layouts/app/header.blade.php's
        // `<livewire:layouts::header />`). See docs/host-requirements.md's
        // `config/livewire.php` row.
        $app->make(Repository::class)->set('livewire.component_namespaces', [
            'layouts' => dirname(__DIR__).'/resources/views/layouts',
            'pages' => dirname(__DIR__).'/resources/views/pages',
        ]);

        $app->make(Repository::class)->set('permission.models.permission', Permission::class);
        $app->make(Repository::class)->set('permission.models.role', Role::class);
        $app->make(Repository::class)->set('permission.column_names.model_morph_key', 'model_id');
        $app->make(Repository::class)->set('permission.table_names', [
            'roles' => 'roles',
            'permissions' => 'permissions',
            'model_has_permissions' => 'model_has_permissions',
            'model_has_roles' => 'model_has_roles',
            'role_has_permissions' => 'role_has_permissions',
        ]);
        $app->make(Repository::class)->set('permission.cache.store', 'array');

        $app->make(Repository::class)->set('cashier.model', \App\Models\Central\Tenant::class);
        $app->make(Repository::class)->set('cashier.key', 'pk_test_dummy');
        $app->make(Repository::class)->set('cashier.secret', 'sk_test_dummy');
        $app->make(Repository::class)->set('cashier.currency', 'usd');

        $app->make(Repository::class)->set('queue.default', 'sync');

        // Gated by QUEUE_FAILED_DRIVER, not by queue.default — see
        // .claude/rules/exception-handling.md. The package ships the central
        // `failed_jobs` migration, so the host has to point this at a
        // connection that carries it; Testbench's skeleton points at sqlite,
        // and the failure is `Database file at path […]/database.sqlite does
        // not exist`, which names neither this key nor failed_jobs. Named
        // 'central' explicitly, not 'mysql' — HostConfig::failedJobsConnection()
        // now normalizes exactly that coincidental-with-database.default
        // pattern, so leaving it as 'mysql' here would make every reboot
        // "fix" it and break HostConfigTest's idempotency assertion.
        $app->make(Repository::class)->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'central',
            'table' => 'failed_jobs',
        ]);
        $app->make(Repository::class)->set('mail.default', 'array');

        // spatie/laravel-activitylog is a "suggest" in composer.json (moved
        // there so it isn't forced on every consumer — see
        // Nvade\Numerosis\Support\Compat\LogsActivityIfInstalled), but the
        // package's own require-dev pulls it in for the test suite, so this
        // config/migration must exist here regardless of ActivityLogFeature
        // (which only gates the Filament UI on top of it).
        $app->make(Repository::class)->set('activitylog.database_connection', null);
        $app->make(Repository::class)->set('activitylog.table_name', 'activity_log');
        $app->make(Repository::class)->set('activitylog.activity_model', Activity::class);
        $app->make(Repository::class)->set('activitylog.default_log_name', 'default');
        $app->make(Repository::class)->set('activitylog.default_auth_driver', null);
        $app->make(Repository::class)->set('activitylog.subject_returns_soft_deleted_models', false);
        $app->make(Repository::class)->set('activitylog.enabled', true);

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
    private function stubViteManifest(Application $app): void
    {
        $buildDir = $app->publicPath('build');

        if (! is_dir($buildDir)) {
            mkdir($buildDir, 0755, true);
        }

        $entries = [
            'resources/css/app.css',
            'resources/js/app.js',
        ];
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
     * (see `routes/tenant.php`'s consumer). The aliases that name resolves
     * to no longer need replicating here: `NumerosisServiceProvider::
     * registerMiddleware()` registers them against the router directly
     * during package boot, which happens for Testbench the same as for a
     * real host — see that method's docblock for why it can do this at
     * runtime while `Numerosis::middleware()` (the `bootstrap/app.php`
     * builder-object version) cannot be reused for it.
     */
    protected function defineRoutes($router): void
    {
        Numerosis::routes();
    }

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
        // Registered before parent::setUp() so that under Testbench — whose
        // beforeApplicationDestroyed() is `array_unshift`, i.e.
        // last-registered runs *first* — this lands behind the rollback
        // RefreshDatabase registers during parent::setUp() rather than ahead
        // of it. The trait no longer depends on winning that race (it ends
        // tenancy and releases the test's transactions itself), but running
        // after the rollback is still one fewer thing happening out of order,
        // and calling it here is what a plain-Laravel host would do too.
        $this->setUpCleansUpTenancyDatabases();

        $this->beforeApplicationDestroyed(function (): void {
            Features::forceForTesting(null);
        });

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->keepDatabaseSchema();

        // TurnstileFeature::$forcedForTesting is a plain static, not
        // container-scoped, so a test that calls forceForTesting() would
        // otherwise leak its override into whichever test runs next in the
        // same process — same class of trap as Tenant::unsetEventDispatcher()
        // (see .claude/rules/testing.md).
        TurnstileFeature::forceForTesting(null);
    }

    /**
     * Databases `CloneTenantSchema` created, which the `tenants` table cannot
     * name: the clone path writes tenant rows through `forceCreate()` and
     * builds databases whose names it alone recorded, and tests routinely
     * delete their tenant row themselves before teardown runs.
     *
     * @return list<string>
     */
    protected function additionalTenantDatabases(): array
    {
        return array_values(CloneTenantSchema::takeCreatedDatabases());
    }

    /**
     * The clone template is built once per process and matches the same
     * `tenant%` shape everything else here drops. Dropping it would make the
     * next test rebuild it, which is the entire cost this speedup avoids.
     *
     * @return list<string>
     */
    protected function preservedTenantDatabases(): array
    {
        return [CloneTenantSchema::templateDatabase()];
    }
}

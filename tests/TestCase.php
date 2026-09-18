<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests;

use App\Models\Central\CentralUser;
use App\Models\Central\Domain;
use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Nvade\Numerosis\Actions\Tenancy\AddTenantOwner;
use Nvade\Numerosis\Actions\Tenancy\CreateTenant;
use Nvade\Numerosis\Actions\Tenancy\CreateTenantDatabase;
use Nvade\Numerosis\Actions\Tenancy\FinalizeTenantProvisioning;
use Nvade\Numerosis\Actions\Tenancy\LinkTenantSubscription;
use Nvade\Numerosis\Actions\Tenancy\PromoteFirstUserToAdmin;
use Nvade\Numerosis\Database\Seeders\TenantDatabaseSeeder;
use Nvade\Numerosis\Enums\Tenancy\DatabaseDriver;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Models\Role;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\Numerosis\Services\Tenancy\AuthGuardBootstrapper;
use Nvade\Numerosis\Services\Tenancy\SpatiePermissionsBootstrapper;
use Nvade\Numerosis\Testing\CleansUpTenancyDatabases;
use Nvade\Numerosis\Tests\Support\CloneTenantSchema;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Orchestra\Testbench\TestCase as Orchestra;
use PDO;
use Pdo\Mysql;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\UUIDGenerator;

abstract class TestCase extends Orchestra
{
    use CleansUpTenancyDatabases;

    /** Read by both the connection array and the `CREATE DATABASE` that precedes it. */
    private const string MYSQL_HOST = '127.0.0.1';

    /** CI reaches a service container by its alias, where docker-compose publishes on the loopback. */
    private const string MYSQL_HOST_ENV = 'NUMEROSIS_TEST_MYSQL_HOST';

    private const string PGSQL_HOST_ENV = 'NUMEROSIS_TEST_PGSQL_HOST';

    private const string MYSQL_PORT = '3306';

    private const string MYSQL_USERNAME = 'root';

    private const string MYSQL_PASSWORD = 'root';

    private const string PGSQL_HOST = '127.0.0.1';

    private const string PGSQL_PORT = '5432';

    private const string PGSQL_USERNAME = 'postgres';

    private const string PGSQL_PASSWORD = 'postgres';

    /**
     * Connecting to a PostgreSQL server needs a database to connect *to*, and
     * the one being created is not available yet.
     */
    private const string PGSQL_MAINTENANCE_DATABASE = 'postgres';

    private static bool $workerDatabaseMigrated = false;

    protected function getPackageProviders($app): array
    {
        return [
            NumerosisServiceProvider::class,
        ];
    }

    /**
     * Authenticate on the tenant guard, the way a request inside a tenant
     * route group would.
     *
     * `actingAs()`'s default guard is the central one, so a bare call leaves
     * every tenant-guard check false and the failure surfaces as an
     * unrelated 403/redirect. Replaces the panel-aware helper the deleted
     * Filament package used to supply.
     */
    protected function actingAsTenantUser(Authenticatable $user): static
    {
        $this->actingAs($user, Config::string('numerosis.auth.guards.tenant'));

        return $this;
    }

    /**
     * Authenticate on the central guard by its configured name.
     *
     * `actingAs()`'s default is whatever `auth.defaults.guard` says, and the
     * central guard is renameable (`RenamedCentralGuardTest` boots it as
     * `host_central`), so spelling `'web'` at a call site pins a name the
     * host owns.
     */
    protected function actingAsCentralUser(Authenticatable $user): static
    {
        $this->actingAs($user, Config::string('numerosis.auth.guards.central'));

        return $this;
    }

    /**
     * A confirmed authenticator on an existing central account, which
     * `EnsureStaffTwoFactor` and a tenant's own requirement both read.
     *
     * @template TUser of BaseCentralUser
     *
     * @param  TUser  $user
     * @return TUser
     */
    protected function withConfirmedTwoFactor(BaseCentralUser $user): BaseCentralUser
    {
        resolve(EnableTwoFactorAuthentication::class)($user);

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user;
    }

    /** The code the user's authenticator app would be showing right now. */
    protected function currentTwoFactorCode(BaseCentralUser $user): string
    {
        $secret = Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret);

        $this->assertIsString($secret);

        return resolve(Google2FA::class)->getCurrentOtp($secret);
    }

    /**
     * A provisioned tenant reachable at its own subdomain.
     */
    protected function createTenantWithDomain(string $id, string $name = 'Test Tenant'): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = TestTenant::provisioned(['id' => $id, 'name' => $name]);

        // `CreateTenantDomain` already made one, but off the identification
        // mode rather than off `tenant_pattern`, which is what the suite's
        // own requests are built from.
        $tenant->domains()->updateOrCreate(['id' => $id], ['domain' => $this->tenantDomain($id)]);

        return $tenant;
    }

    /**
     * A user inside the tenant's own database, returned already resolved on
     * the tenant connection so `actingAsTenantUser()` takes it directly.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createTenantUser(Tenant $tenant, array $attributes = []): User
    {
        /** @var User $user */
        $user = $tenant->run(fn (): User => User::create([
            'global_id' => 'global-'.uniqid(),
            'name' => 'Tenant User',
            'email' => 'tenant-'.uniqid().'@example.com',
            ...$attributes,
        ]));

        return $user;
    }

    /**
     * Testbench's `ignorePackageDiscoveriesFrom()` defaults to `['*']` unless
     * either this is overridden or the test case composes `WithWorkbench` —
     * without one of those, `PackageManifest::getManifest()` rejects every
     * vendor package's discovered providers *and* aliases, silently, no
     * error at boot. Every vendor dependency this package relies on
     * (`livewire/livewire`'s `Livewire` facade alias and `livewire.finder`
     * binding, …) is reached through Laravel's
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

        // Migrating and seeding a real tenant database costs ~1.9s, and
        // QUEUE_CONNECTION=sync makes every provision pay it inline. Copying a
        // template built once per process costs a fraction of that, so the
        // migrate and seed steps are swapped for CloneTenantSchema.
        //
        // Set here rather than in Pest.php: there is no application yet when
        // that file loads, and every test rebuilds config from the package's
        // own file.
        $app->make(Repository::class)->set('numerosis.tenancy.provisioning.steps', [
            CreateTenant::class,
            CreateTenantDatabase::class,
            CloneTenantSchema::class,
            AddTenantOwner::class,
            PromoteFirstUserToAdmin::class,
            LinkTenantSubscription::class,
            FinalizeTenantProvisioning::class,
        ]);

        // HostConfig::apply() already ran (see the boot-order note below) and
        // put uncompromised() on Password::defaults(), which would call the
        // Have I Been Pwned API from every test that sets a password.
        // CompromisedPasswordTest opts back in with the HTTP client faked.
        $app->make(Repository::class)->set('numerosis.auth.check_compromised_passwords', false);
        Password::defaults(static fn (): Password => Password::min(8));

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

        // `central` and `tenant` have to follow the parallel token themselves.
        // Laravel's own parallel wiring (Illuminate\Testing\Concerns\
        // TestDatabases) switches exactly one connection — the default — and it
        // does so *after* this method has run, so a hardcoded 'testing' here
        // leaves every central-connection read pointed at the shared database
        // while the default correctly moved to the worker's own. That is not a
        // slow or flaky run: it is 376 failures reading
        // `Connection: central, Database: testing`, because nothing ever
        // migrated the database those queries land in.
        $database = static::parallelAwareDatabase('testing');
        static::ensureDatabaseExists($database);

        $connection = static::connectionConfig($database, $lockWaitTimeout);

        $name = static::databaseDriver()->value;

        // On SQLite the default connection *is* `central`, one Connection
        // object and one PDO handle. Two handles on one file deadlock the
        // moment RefreshDatabase's transaction takes the write lock and a
        // central-connection write asks for it: pdo_sqlite defaults
        // PDO::ATTR_TIMEOUT to 60 seconds, so each blocked statement sleeps
        // rather than failing, and the run reads as hung.
        $default = static::databaseDriver() === DatabaseDriver::Sqlite ? 'central' : $name;

        $app->make(Repository::class)->set('database.default', $default);
        $app->make(Repository::class)->set('database.connections.'.$name, $connection);
        $app->make(Repository::class)->set('database.connections.central', $connection);
        $app->make(Repository::class)->set('database.connections.tenant', $connection);

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
        // .ai/rules/testing.md for the recorded version.
        Config::set('tenancy.tenant_model', Tenant::class);
        Config::set('tenancy.id_generator', UUIDGenerator::class);
        Config::set('tenancy.domain_model', Domain::class);
        $app->make(Repository::class)->set('tenancy.central_user_model', CentralUser::class);
        $app->make(Repository::class)->set('tenancy.tenant_user_model', User::class);
        Config::set('tenancy.central_domains', ['central.numerosistest.test']);
        $app->make(Repository::class)->set('tenancy.bootstrappers', [
            DatabaseTenancyBootstrapper::class,
            CacheTenancyBootstrapper::class,
            FilesystemTenancyBootstrapper::class,
            QueueTenancyBootstrapper::class,
            SpatiePermissionsBootstrapper::class,
            AuthGuardBootstrapper::class,
        ]);
        $app->make(Repository::class)->set('tenancy.database.central_connection', 'central');
        // Tenant database names are derived from this prefix plus the tenant
        // id, and tenant ids here come from the faker — so with one shared
        // prefix, two workers that happen to generate the same id share one
        // physical database, and whichever finishes first drops it out from
        // under the other. The token belongs in the prefix rather than in each
        // caller: it is the single point every tenant database name, including
        // CloneTenantSchema's template, is built from.
        $app->make(Repository::class)->set('tenancy.database.prefix', static::parallelAwareTenantPrefix());
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
        // `Enums\Auth\SocialProvider::isConfigured()` tests
        // `services.{provider}.client_id` directly — the block below is what
        // makes google/discord "configured" in tests, everything else stays
        // unconfigured on purpose.
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
        // production (see .ai/rules/exception-handling.md's `failed_jobs`
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
        // see .ai/rules/stancl-tenancy-v4.md); this package's tests
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

        // `numerosis-layouts`/`numerosis-pages` are registered by
        // NumerosisServiceProvider::registerLivewireComponentNamespaces(),
        // which no longer touches Livewire's own `layouts`/`pages` keys.

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

        $app->make(Repository::class)->set('cashier.model', Tenant::class);
        $app->make(Repository::class)->set('cashier.key', 'pk_test_dummy');
        $app->make(Repository::class)->set('cashier.secret', 'sk_test_dummy');
        $app->make(Repository::class)->set('cashier.currency', 'usd');

        $app->make(Repository::class)->set('queue.default', 'sync');

        // Gated by QUEUE_FAILED_DRIVER, not by queue.default — see
        // .ai/rules/exception-handling.md. The package ships the central
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

        $app->make(Repository::class)->set('activitylog.database_connection');
        $app->make(Repository::class)->set('activitylog.table_name', 'activity_log');
        $app->make(Repository::class)->set('activitylog.activity_model', Activity::class);
        $app->make(Repository::class)->set('activitylog.default_log_name', 'default');
        $app->make(Repository::class)->set('activitylog.default_auth_driver');
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
     *
     * **Written atomically, and that is not tidiness.** This runs on every
     * test's application boot, and `--parallel` gives eight worker processes
     * that all target this one path under `workbench/public`. A plain
     * `file_put_contents()` truncates before it writes, so a worker reading
     * the file during another worker's write gets partial JSON;
     * `json_decode()` returns null and `Illuminate\Foundation\Vite` reports
     * it as `Unable to locate file in Vite manifest: resources/css/app.css`
     * — pointing at whichever view happened to render, never at this method.
     * A `rename()` on the same filesystem is atomic, so a reader sees either
     * the old complete file or the new one.
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

        self::writeManifestAtomically($buildDir.'/manifest.json', $manifest);
    }

    /**
     * Temp name carries the pid: two workers renaming the *same* temp file
     * would reintroduce the race this exists to remove.
     *
     * @param  array<string, array{file: string, src: string, isEntry: bool}>  $manifest
     */
    protected static function writeManifestAtomically(string $path, array $manifest): void
    {
        $temporary = $path.'.'.getmypid().'.tmp';

        file_put_contents($temporary, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        rename($temporary, $path);
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
     * The connection every central model reads. Query-log assertions have to
     * name it: the default connection is a different one, so logging there
     * records nothing and an "issued no query" assertion passes vacuously.
     */
    protected function centralDatabase(): Connection
    {
        return DB::connection(Config::string('tenancy.database.central_connection', 'central'));
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

    /**
     * For a test whose subject is a driver's own behaviour rather than this
     * package's, so it has nothing to assert on the others.
     */
    protected function skipUnlessDriverIs(DatabaseDriver ...$drivers): void
    {
        $driver = static::databaseDriver();

        if (! in_array($driver, $drivers, true)) {
            $this->markTestSkipped("Covers {$drivers[0]->value}; this run is on {$driver->value}.");
        }
    }

    /**
     * The driver this run is exercising. MySQL unless `NUMEROSIS_TEST_DRIVER`
     * says otherwise, so a run that opts into nothing behaves as it always did.
     */
    public static function databaseDriver(): DatabaseDriver
    {
        $name = getenv('NUMEROSIS_TEST_DRIVER');

        if (! is_string($name) || $name === '') {
            return DatabaseDriver::Mysql;
        }

        return DatabaseDriver::tryFrom($name)
            ?? throw new RuntimeException("NUMEROSIS_TEST_DRIVER is '{$name}', which this suite has no connection array for.");
    }

    /**
     * @return array<string, mixed>
     */
    protected static function connectionConfig(string $database, int $lockWaitTimeout): array
    {
        return match (static::databaseDriver()) {
            DatabaseDriver::Sqlite => [
                'driver' => 'sqlite',
                'database' => static::sqlitePath($database),
                'prefix' => '',
                'prefix_indexes' => true,
                'foreign_key_constraints' => true,
                'busy_timeout' => 10_000,
                'journal_mode' => null,
                'synchronous' => null,
            ],
            DatabaseDriver::Pgsql => [
                'driver' => 'pgsql',
                'host' => self::pgsqlHost(),
                'port' => self::PGSQL_PORT,
                'database' => $database,
                'username' => self::PGSQL_USERNAME,
                'password' => self::PGSQL_PASSWORD,
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
                // PostgreSQL's analogue of the MySQL init command below. Both
                // are bounded so a blocked statement fails the test rather
                // than hanging the worker.
                'options' => extension_loaded('pdo_pgsql') ? [
                    PDO::ATTR_TIMEOUT => $lockWaitTimeout,
                ] : [],
            ],
            default => [
                'driver' => static::databaseDriver()->value,
                'host' => self::mysqlHost(),
                'port' => self::MYSQL_PORT,
                'database' => $database,
                'username' => self::MYSQL_USERNAME,
                'password' => self::MYSQL_PASSWORD,
                'unix_socket' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_0900_ai_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
                'options' => extension_loaded('pdo_mysql') ? [
                    Mysql::ATTR_INIT_COMMAND => "SET SESSION lock_wait_timeout = {$lockWaitTimeout}, innodb_lock_wait_timeout = {$lockWaitTimeout}",
                ] : [],
            ],
        };
    }

    /**
     * The central database is a file beside the tenant files stancl's
     * `SQLiteDatabaseManager` writes, and takes an extension so a glob for the
     * tenant prefix cannot pick it up.
     */
    protected static function sqlitePath(string $database): string
    {
        return database_path($database.'.sqlite');
    }

    /**
     * A database name suffixed with this worker's parallel token, matching the
     * `{name}_test_{token}` shape `TestDatabases::testDatabase()` uses for the
     * default connection, so every connection in this suite lands in one
     * database per worker. Returns `$name` unchanged when not in parallel.
     */
    public static function parallelAwareDatabase(string $name): string
    {
        $token = ParallelTesting::token();

        return $token !== false && $token !== '' ? $name.'_test_'.$token : $name;
    }

    /**
     * Create this worker's database, because nothing else will.
     *
     * `TestDatabases`'s setUpProcess hook is what creates `{name}_test_{token}`
     * in an application, and only `Illuminate\Testing\ParallelRunner` invokes
     * it. Pest installs that runner only when `Orchestra\Testbench\TestCase` is
     * absent, so in a package it never runs: `Unknown database` on every query.
     */
    protected static function ensureDatabaseExists(string $database): void
    {
        static $ensured = [];

        if (isset($ensured[$database])) {
            return;
        }

        match (static::databaseDriver()) {
            DatabaseDriver::Sqlite => self::ensureSqliteFileExists($database),
            DatabaseDriver::Pgsql => self::ensurePostgresDatabaseExists($database),
            default => self::ensureMysqlDatabaseExists($database),
        };

        $ensured[$database] = true;
    }

    private static function mysqlHost(): string
    {
        return getenv(self::MYSQL_HOST_ENV) ?: self::MYSQL_HOST;
    }

    private static function pgsqlHost(): string
    {
        return getenv(self::PGSQL_HOST_ENV) ?: self::PGSQL_HOST;
    }

    private static function ensureMysqlDatabaseExists(string $database): void
    {
        if (! extension_loaded('pdo_mysql')) {
            return;
        }

        $connection = new PDO('mysql:host='.self::mysqlHost().';port='.self::MYSQL_PORT, self::MYSQL_USERNAME, self::MYSQL_PASSWORD);
        $connection->exec("create database if not exists `{$database}` character set utf8mb4 collate utf8mb4_0900_ai_ci");
    }

    /**
     * PostgreSQL has no `CREATE DATABASE IF NOT EXISTS`, and issuing the
     * create unconditionally throws rather than reporting the database is
     * already there.
     */
    private static function ensurePostgresDatabaseExists(string $database): void
    {
        if (! extension_loaded('pdo_pgsql')) {
            return;
        }

        $connection = new PDO(
            'pgsql:host='.self::pgsqlHost().';port='.self::PGSQL_PORT.';dbname='.self::PGSQL_MAINTENANCE_DATABASE,
            self::PGSQL_USERNAME,
            self::PGSQL_PASSWORD,
        );

        $statement = $connection->prepare('select 1 from pg_database where datname = ?');
        $statement->execute([$database]);

        if ($statement->fetchColumn() === false) {
            $connection->exec('create database "'.str_replace('"', '""', $database).'"');
        }
    }

    private static function ensureSqliteFileExists(string $database): void
    {
        $path = static::sqlitePath($database);

        if (! is_file($path)) {
            file_put_contents($path, '');
        }
    }

    /**
     * The `tenancy.database.prefix` this worker builds tenant database names
     * from. `tenant` when serial, `tenant{token}_` under `--parallel`.
     *
     * Everything that drops tenant databases in this suite derives the names it
     * drops from per-worker state — surviving `tenants` rows on the worker's own
     * central connection, plus `CloneTenantSchema::takeCreatedDatabases()` —
     * so a per-worker prefix does not need a matching change in teardown.
     */
    public static function parallelAwareTenantPrefix(): string
    {
        $token = ParallelTesting::token();

        return $token !== false && $token !== '' ? 'tenant'.$token.'_' : 'tenant';
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
        Numerosis::resetMiddlewareRegisteredForTesting();

        $this->setUpCleansUpTenancyDatabases();

        $this->beforeApplicationDestroyed(function (): void {
            FeatureRegistry::forceForTesting(null);
        });

        parent::setUp();

        $this->shareOneTransactionManager();

        $this->migrateWorkerDatabaseOnce();
    }

    /**
     * `RefreshDatabase::beginDatabaseTransaction()` rebinds `db.transactions`
     * and hands the new manager only to the default connection, so `central`,
     * resolved earlier, keeps the old one. The dispatcher reads the
     * container's, so a `ShouldDispatchAfterCommit` event raised inside a
     * central transaction saw no pending transaction and fired immediately.
     */
    private function shareOneTransactionManager(): void
    {
        if ($this->app === null || ! $this->app->bound('db.transactions')) {
            return;
        }

        // Array access, not make(): `db.transactions` has no class alias, so
        // make(DatabaseTransactionsManager::class) builds a second manager
        // that no connection and no dispatcher ever reads.
        $manager = $this->app['db.transactions'];

        if (! $manager instanceof DatabaseTransactionsManager) {
            return;
        }

        foreach ($this->app->make(ConnectionResolverInterface::class)->getConnections() as $connection) {
            $connection->setTransactionManager($manager);
        }
    }

    /**
     * Migrate this worker's database, once per process.
     *
     * `RefreshDatabase` migrates only for the tests that use it, and the
     * class-based half of this suite does not. So whichever test a worker
     * happens to start with decides whether the schema exists at all — and
     * `keepDatabaseSchema()` then pins that answer, empty or not, for every
     * test the process runs after it.
     */
    private function migrateWorkerDatabaseOnce(): void
    {
        if (self::$workerDatabaseMigrated) {
            return;
        }

        if (! $this->workerDatabaseHasSchema()) {
            $this->artisan('migrate:fresh', ['--force' => true]);
        }

        // Read back rather than assume: a migration that did not happen leaves
        // the flag false, so the next test tries again instead of pinning an
        // empty database for the rest of the process.
        self::$workerDatabaseMigrated = $this->workerDatabaseHasSchema();

        RefreshDatabaseState::$migrated = self::$workerDatabaseMigrated;
    }

    private function workerDatabaseHasSchema(): bool
    {
        return $this->app?->make('db')
            ->connection(Config::string('tenancy.database.central_connection'))
            ->getSchemaBuilder()
            ->hasTable('users') ?? false;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->keepDatabaseSchema();
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

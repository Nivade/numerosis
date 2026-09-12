<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use App\Models\Central\CentralUser;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvade\Numerosis\Actions\Tenancy\AddTenantOwner;
use Nvade\Numerosis\Actions\Tenancy\CreateTenant;
use Nvade\Numerosis\Actions\Tenancy\CreateTenantDatabase;
use Nvade\Numerosis\Actions\Tenancy\FinalizeTenantProvisioning;
use Nvade\Numerosis\Actions\Tenancy\LinkTenantSubscription;
use Nvade\Numerosis\Actions\Tenancy\MigrateTenantDatabase;
use Nvade\Numerosis\Actions\Tenancy\ProvisionTenant;
use Nvade\Numerosis\Actions\Tenancy\SeedTenantDatabase;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\Numerosis\Services\Tenancy\Bootstrappers\AuthGuardBootstrapper;
use Nvade\Numerosis\Services\Tenancy\Bootstrappers\SpatiePermissionsBootstrapper;
use Orchestra\Testbench\TestCase as Orchestra;
use PDO;

/**
 * Proves docs/host-requirements.md §1's own claim: a host supplying only the
 * six irreducible obligations (`APP_URL`, Stripe keys, DB credentials +
 * `migrate`, the `bootstrap/app.php` routing/middleware hook, asset publishing,
 * a provisioning worker) gets a fully working multi-tenant SaaS with zero
 * `numerosis.*`/`tenancy.*`/`auth.*` config of its own — this is the test
 * `.claude/plans/archive/better-dx.md`'s "Verification" section calls for and the
 * whole plan otherwise has no automated check for.
 *
 * Deliberately does **not** extend `Tests\TestCase`: that class exists to
 * *stand in* for a host by hand-setting ~290 lines of config, which is
 * exactly what this test exists to prove unnecessary. Every other test in
 * this suite runs through that heavy harness; this is the one place
 * `HostConfig` has to prove itself rather than being handed already-correct
 * config.
 *
 * `APP_URL`/`DB_*`/`STRIPE_*` are set via `putenv()` in `setUp()`, *before*
 * `parent::setUp()` — not via `Config::set()` in `getEnvironmentSetUp()`.
 * `.ai/rules/testing.md`'s "`TestCase::getEnvironmentSetUp()` runs after
 * providers register" section is exactly why: `HostConfig::apply()` (and
 * `config/numerosis.php`'s own `Domains::apexFromAppUrl()`, and
 * `config/database.php`'s `env('DB_*')` reads) all resolve during
 * `RegisterProviders`, which Testbench runs strictly before
 * `getEnvironmentSetUp()` fires — a `Config::set()` there would be read by
 * nothing. Real env vars, set before the application even starts
 * bootstrapping, are what a real host's `.env` file provides at the
 * equivalent point (`LoadConfiguration`, also pre-registration) — so this is
 * not a workaround, it is the only way to faithfully simulate one.
 *
 * `config/permission.php` is deliberately never published here. Both
 * seeders (`RoleAndPermissionSeeder`, `Tenant/PermissionAndRoleSeeder`)
 * import `Nvade\Numerosis\Models\{Role,Permission}` directly rather than
 * resolving them through `config('permission.models.*')`, and nothing here
 * defines a morph map — so Spatie's own stock model classes (the package's
 * un-overridden default) resolve fine against the same `roles`/`permissions`
 * tables the seeders wrote through the numerosis subclasses. Confirmed
 * empirically by this test passing, not asserted directly.
 */
class FreshHostTest extends Orchestra
{
    private const string APP_URL = 'http://freshhost.test';

    private const string DB_DATABASE = 'testing_fresh_host';

    /** @var list<class-string>|null */
    /** @var list<class-string>|null */
    private ?array $originalProvisioningSteps = null;

    private ?string $provisionedTenantDatabase = null;

    /**
     * The real `putenv()` calls in `setUp()` below never got reversed —
     * `forgetMemoizedEnvironmentRepository()` only resets Laravel's own
     * cached view of the environment, not the process environment itself,
     * so `CACHE_STORE=array`/`QUEUE_CONNECTION=sync` silently stayed real
     * for the rest of the PHP process once this test had run once. Under
     * v3 nothing downstream cared; on `stancl/tenancy:dev-master`,
     * `CacheTenancyBootstrapper` rejects an `array`-driver store outright,
     * so *every* later test in the same process that enters tenant context
     * started throwing "Cache store [array] is not supported by this
     * bootstrapper." — order-dependent, only after this test had run.
     * `$envKeysSet` records exactly the keys this test's `setUp()` put into
     * the real environment, so `tearDown()` can `putenv($key)` (unset form)
     * and clear `$_ENV`/`$_SERVER` for each, undoing the leak rather than
     * only its symptom.
     *
     * @var list<string>
     */
    private array $envKeysSet = [];

    protected function getPackageProviders($app): array
    {
        return [NumerosisServiceProvider::class];
    }

    public function ignorePackageDiscoveriesFrom(): array
    {
        return [];
    }

    protected function defineRoutes($router): void
    {
        // The one substitute this harness needs for a real host's one-line
        // bootstrap/app.php (obligation #4) — Testbench has no such file to
        // call Numerosis::configure()/withRouting() from. Everything this
        // call reads (tenancy.central_domains) is HostConfig-derived, not
        // hand-set anywhere in this file.
        \Nvade\Numerosis\Numerosis::routes();
    }

    /**
     * Sets exactly the six obligations `docs/host-requirements.md` §1 lists
     * (`APP_URL`, Stripe keys, DB credentials) as real process environment
     * variables — see the class docblock for why `putenv()` here, before
     * `parent::setUp()`, rather than `Config::set()` in
     * `getEnvironmentSetUp()`. Asset publishing/migrate/the routing hook/
     * DNS+worker obligations are satisfied by this method's other lines
     * (migrate below), `defineRoutes()`, and `QUEUE_CONNECTION=sync`
     * respectively.
     */
    protected function setUp(): void
    {
        foreach ([
            'APP_URL' => self::APP_URL,
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('f', 32)),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => self::DB_DATABASE,
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => 'root',
            'STRIPE_KEY' => 'pk_test_dummy',
            'STRIPE_SECRET' => 'sk_test_dummy',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_test_dummy',

            // The two below are infrastructure choices, in the same category
            // as this file's `docs/host-requirements.md` §1 obligation #6
            // ("a queue worker runs on the `provisioning` queue") — not
            // `numerosis.*`/`tenancy.*`/`auth.*` config, which is what this
            // test exists to prove unnecessary. Both are set explicitly
            // because Testbench's own skeleton `.env` picks `database` for
            // each, and neither is what this harness can run against:
            //
            // - `sync` because this test asserts the provisioning pipeline's
            //   *result* inline and no worker process exists to drain a real
            //   queue. Note this is NOT Testbench's default — its `.env` says
            //   `QUEUE_CONNECTION=database`, matching a fresh Laravel install.
            // - `array` because `CacheTenancyBootstrapper` isolates tenants
            //   with cache *tags*, and Laravel's `database` store is not
            //   taggable (see `.ai/rules/tenant-caching.md`). A host on
            //   `CACHE_STORE=database` is already outside what this package
            //   supports, so pinning it here changes nothing a real host
            //   would have gotten away with.
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array',
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            $this->envKeysSet[] = $key;
        }

        // The physical database has to exist before Laravel can even connect
        // to it — migrate:fresh drops/recreates tables inside a database,
        // not the database itself. docker-compose.yml only provisions
        // `testing` (what every other test in this suite shares); this test
        // gets its own so a fresh migrate:fresh here can never interact with
        // — or be starved by — the shared suite database.
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'root');
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.self::DB_DATABASE.'`');

        // Without this, every putenv() above is silently reverted to
        // Testbench's own `.env` value on the second and later boots in a
        // process — so this test passes run alone and fails run in the suite.
        self::forgetMemoizedEnvironmentRepository();

        parent::setUp();

        // TestCase swaps the migrate and seed steps for the CloneTenantSchema
        // shortcut, which is right for speed everywhere else and wrong here:
        // this test's whole point is the *real* steps running against nothing
        // but HostConfig's own defaults. Set after parent::setUp() because
        // there is no facade root before it. Restored in tearDown().
        /** @var list<class-string> $steps */
        $steps = Config::array('numerosis.tenancy.provisioning.steps');
        $this->originalProvisioningSteps = $steps;
        Config::set('numerosis.tenancy.provisioning.steps', [
            CreateTenant::class,
            CreateTenantDatabase::class,
            MigrateTenantDatabase::class,
            SeedTenantDatabase::class,
            AddTenantOwner::class,
            LinkTenantSubscription::class,
            FinalizeTenantProvisioning::class,
        ]);

        $this->stubViteManifest();

        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->provisionedTenantDatabase !== null) {
            $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'root');
            $name = str_replace('`', '``', $this->provisionedTenantDatabase);
            $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
        }

        if ($this->originalProvisioningSteps !== null) {
            Config::set('numerosis.tenancy.provisioning.steps', $this->originalProvisioningSteps);
        }

        parent::tearDown();

        // Dedicated database, unused by any other test — dropped wholesale
        // rather than reasoning about which connection wrote what, unlike
        // CleansUpTenancyDatabases' central-write and tenant-database sweeps,
        // which exist only because that harness shares `testing` with
        // every other test in the suite.
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'root');
        $pdo->exec('DROP DATABASE IF EXISTS `'.self::DB_DATABASE.'`');

        // Undo the real putenv() calls setUp() made, not just Laravel's
        // cached view of them — see $envKeysSet's docblock.
        foreach ($this->envKeysSet as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        // Same reset on the way out, so this test's own env does not become
        // the stale `$loaded` state that breaks whichever test boots next.
        self::forgetMemoizedEnvironmentRepository();
    }

    /**
     * Drops `Illuminate\Support\Env`'s memoized repository so the next read
     * treats this test's `putenv()` values as externally defined again.
     *
     * `Env::$repository` is **static**, built once per process, and wrapped
     * in phpdotenv's `ImmutableWriter`. That writer keeps a `$loaded` array
     * of every key it has written, and its `isExternallyDefined()` check is
     * `$this->reader->read($name)->isDefined() && ! isset($this->loaded[$name])`
     * — so a key it has already written once is no longer considered
     * externally defined, and `Dotenv::load()` is free to overwrite it on
     * the next call.
     *
     * Every Testbench boot runs `LoadEnvironmentVariables`, which loads
     * `vendor/orchestra/testbench-core/laravel/.env` (`DB_CONNECTION=sqlite`,
     * `QUEUE_CONNECTION=database`, `CACHE_STORE=database`). On the *first*
     * boot in a process the `putenv()` calls above win, because `$loaded` is
     * empty and the values genuinely are external. On every boot after that
     * `$loaded` still carries those keys from the previous boot, so the
     * `.env` silently clobbers them.
     *
     * That is exactly why this file used to pass under `--filter=FreshHostTest`
     * and fail in the full suite: `DB_CONNECTION` reverted to `sqlite`,
     * Testbench's `LoadConfiguration::configureDefaultDatabaseConnection()`
     * then saw `sqlite` with no database file and rewrote `database.default`
     * to its in-memory `testing` connection, and `HostConfig` cloned *that*
     * into the `central` connection. `DB_DATABASE` was never in the `.env`,
     * so it alone survived — which is what made the failure read as an
     * incoherent mix of MySQL and SQLite rather than as one env problem.
     *
     * `Env::enablePutenv()` is the public way to discard the repository (it
     * nulls it so the next `getRepository()` rebuilds with a fresh, empty
     * `ImmutableWriter`). Enabling the putenv adapter is also exactly what
     * this test wants on its own terms, since `setUp()` sets its environment
     * through `putenv()`.
     */
    private static function forgetMemoizedEnvironmentRepository(): void
    {
        Env::enablePutenv();
    }

    /**
     * The package ships Vite *sources* only — a real host builds them (or
     * rides the prebuilt `dist/` assets for numerosis's own JS/CSS, Phase 3
     * of better-dx.md). Testbench has no build step, so anything hitting
     *
     * @vite() needs a stub manifest — a harness need, not something
     * HostConfig is responsible for.
     *
     * Duplicated from `Tests\TestCase::stubViteManifest()` rather than
     * inherited, because this class extends Orchestra directly. Written
     * through a temp file plus `rename()` for the same reason as that copy:
     * under `--parallel`, every worker targets this one path, and a plain
     * `file_put_contents()` lets a concurrent reader see truncated JSON.
     * See that method's docblock for how the failure presents.
     */
    private function stubViteManifest(): void
    {
        $buildDir = public_path('build');

        if (! is_dir($buildDir)) {
            mkdir($buildDir, 0755, true);
        }

        $manifest = [];

        foreach (['resources/css/app.css', 'resources/js/app.js'] as $entry) {
            $manifest[$entry] = [
                'file' => 'assets/'.basename($entry),
                'src' => $entry,
                'isEntry' => true,
            ];
        }

        $path = $buildDir.'/manifest.json';
        $temporary = $path.'.'.getmypid().'.tmp';

        file_put_contents($temporary, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        rename($temporary, $path);
    }

    /**
     * `login` is Fortify's route, loaded into this host's central-domain
     * group by `Numerosis::routes()` — so this also covers 4a's per-group
     * `require` of `routes/routes.php` against a host that configured
     * nothing.
     */
    public function test_login_renders_with_zero_explicit_numerosis_tenancy_or_auth_config(): void
    {
        $response = $this->get(self::APP_URL.'/login');

        $response->assertOk();
        $response->assertSeeText('Log in');
    }

    public function test_tenancy_bootstrappers_carry_both_package_bootstrappers(): void
    {
        $bootstrappers = Config::array('tenancy.bootstrappers');

        $this->assertContains(SpatiePermissionsBootstrapper::class, $bootstrappers);
        $this->assertContains(AuthGuardBootstrapper::class, $bootstrappers);
    }

    public function test_the_tenant_auth_guard_resolves_end_to_end(): void
    {
        $this->assertSame([
            'driver' => 'session',
            'provider' => 'tenant',
        ], Config::get('auth.guards.tenant'));

        // Not just present in config — actually resolvable through Auth,
        // proving auth.providers.tenant's model exists and the guard/
        // provider pair is internally consistent, not merely well-formed.
        $this->assertFalse(Auth::guard('tenant')->check());
    }

    public function test_a_tenant_provisions_end_to_end_through_the_real_pipeline(): void
    {
        $owner = CentralUser::factory()->create();

        // Through the entry point production uses, not by creating a Tenant
        // row: building a database is a provisioning step now, never a side
        // effect of a model event.
        ProvisionTenant::make()->queue(new TenantProvisionData(
            slug: 'freshhosttenant',
            name: 'Fresh Host Co',
            global_id: $owner->global_id,
        ));

        $tenant = Tenant::findOrFail('freshhosttenant');

        $prefix = Config::string('tenancy.database.prefix', 'tenant');
        $this->provisionedTenantDatabase = $prefix.$tenant->getTenantKey();

        // QUEUE_CONNECTION=sync, forced in setUp() above, is what makes every
        // link of the chain -- including the real database steps this setUp()
        // restored -- run synchronously, inline, right here.
        $this->assertTrue(
            DB::connection('central')->getSchemaBuilder()->hasTable('tenants'),
        );

        tenancy()->initialize($tenant);

        try {
            $this->assertTrue(Schema::hasTable('users'));
            $this->assertGreaterThan(0, DB::table('roles')->count());
            $this->assertGreaterThan(0, DB::table('permissions')->count());
        } finally {
            tenancy()->end();
        }
    }
}

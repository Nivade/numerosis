<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvade\Numerosis\Jobs\SeedTenantDatabase;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Nvade\Numerosis\Services\Tenancy\Bootstrappers\AuthGuardBootstrapper;
use Nvade\Numerosis\Services\Tenancy\Bootstrappers\SpatiePermissionsBootstrapper;
use Orchestra\Testbench\TestCase as Orchestra;
use PDO;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;

/**
 * Proves docs/host-requirements.md §1's own claim: a host supplying only the
 * six irreducible obligations (`APP_URL`, Stripe keys, DB credentials +
 * `migrate`, the `bootstrap/app.php` routing/middleware hook, `filament:assets`,
 * a provisioning worker) gets a fully working multi-tenant SaaS with zero
 * `numerosis.*`/`tenancy.*`/`auth.*` config of its own — this is the test
 * `.claude/plans/better-dx.md`'s "Verification" section calls for and the
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
 * `.claude/rules/testing.md`'s "`TestCase::getEnvironmentSetUp()` runs after
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
    private ?array $originalTenantCreatedJobs = null;

    private ?string $provisionedTenantDatabase = null;

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
        \Nvade\Numerosis\Support\Numerosis::routes();
    }

    /**
     * Sets exactly the six obligations `docs/host-requirements.md` §1 lists
     * (`APP_URL`, Stripe keys, DB credentials) as real process environment
     * variables — see the class docblock for why `putenv()` here, before
     * `parent::setUp()`, rather than `Config::set()` in
     * `getEnvironmentSetUp()`. `filament:assets`/migrate/the routing hook/
     * DNS+worker obligations are satisfied by this method's other lines
     * (migrate below), `defineRoutes()`, and `QUEUE_CONNECTION=sync`
     * (already Testbench's own skeleton default — see below) respectively.
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
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        // The physical database has to exist before Laravel can even connect
        // to it — migrate:fresh drops/recreates tables inside a database,
        // not the database itself. docker-compose.yml only provisions
        // `testing` (what every other test in this suite shares); this test
        // gets its own so a fresh migrate:fresh here can never interact with
        // — or be starved by — the shared suite database.
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'root');
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.self::DB_DATABASE.'`');

        // tests/Pest.php overrides this to the CloneTenantSchema shortcut,
        // process-wide, for every test in the suite — correct for speed
        // everywhere else, wrong here: this test's entire point is proving
        // the *real* CreateDatabase+MigrateDatabase+SeedTenantDatabase
        // pipeline works against nothing but HostConfig's own defaults.
        // Restored in tearDown() so this override doesn't leak into
        // whichever test runs next.
        $this->originalTenantCreatedJobs = TenancyServiceProvider::$tenantCreatedJobs;
        TenancyServiceProvider::$tenantCreatedJobs = [
            CreateDatabase::class,
            MigrateDatabase::class,
            SeedTenantDatabase::class,
        ];

        parent::setUp();

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

        if ($this->originalTenantCreatedJobs !== null) {
            TenancyServiceProvider::$tenantCreatedJobs = $this->originalTenantCreatedJobs;
        }

        parent::tearDown();

        // Dedicated database, unused by any other test — dropped wholesale
        // rather than reasoning about which connection wrote what, unlike
        // Tests\TestCase's deleteCentralWrites()/deleteTenantDatabases(),
        // which exist only because that harness shares `testing` with
        // every other test in the suite.
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'root');
        $pdo->exec('DROP DATABASE IF EXISTS `'.self::DB_DATABASE.'`');
    }

    /**
     * The package ships Vite *sources* only — a real host builds them (or
     * rides the prebuilt `dist/` assets for numerosis's own JS/CSS, Phase 3
     * of better-dx.md). Testbench has no build step, so anything hitting
     *
     * @vite() needs a stub manifest — a harness need, not something
     * HostConfig is responsible for.
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

        file_put_contents($buildDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

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
        $tenant = Tenant::create(['id' => 'freshhosttenant']);

        $prefix = Config::string('tenancy.database.prefix', 'tenant');
        $this->provisionedTenantDatabase = $prefix.$tenant->getTenantKey();

        // QUEUE_CONNECTION=sync (Testbench's own skeleton default — never
        // set by this test) is what makes the TenantCreated pipeline this
        // setUp() restored to the real CreateDatabase+MigrateDatabase+
        // SeedTenantDatabase jobs run synchronously, inline, right here.
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

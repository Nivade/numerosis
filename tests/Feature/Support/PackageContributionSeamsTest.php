<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Contracts\Feature;
use Nvade\Numerosis\Support\Contributions;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\HostConfig;
use Nvade\Numerosis\Support\Numerosis;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\seed;

/*
 * Phase 3 of .claude/plans/archive/memoized-tinkering-meadow.md: additive
 * contribution seams a second package uses to add a route, a feature, or a
 * tenant migration path/seeder without reproducing (or replacing) the
 * package's own registration. Each of these is a static that persists for
 * the process lifetime — same as Numerosis::$registerRoutesCallback — so
 * every test resets it, or a contribution here leaks into an unrelated,
 * later test.
 */

/**
 * `Route::getByName()` reads a lookup table `RouteServiceProvider` rebuilds
 * after loading a route file — a bypass the direct `Numerosis::routes()` calls
 * below never go through, so a freshly added route has to be found by scanning
 * the collection.
 */
function registeredRoute(string $name): Illuminate\Routing\Route
{
    return collect(Route::getRoutes()->getRoutes())
        ->sole(fn (Illuminate\Routing\Route $route): bool => $route->getName() === $name);
}

afterEach(function (): void {
    Numerosis::resetRouteContributionsForTesting();
    Numerosis::resetMigrationAndSeederContributionsForTesting();
    Features::resetRegisteredForTesting();
});

it('runs an addCentralRoutes() callback inside the central domain group, with the same web middleware and domain constraint', function () {
    Numerosis::addCentralRoutes(function (): void {
        Route::get('/__seam-central-probe', fn () => 'ok')->name('seam.central.probe');
    });

    Numerosis::routes();

    $route = registeredRoute('seam.central.probe');
    $centralDomains = Config::array('tenancy.central_domains');

    expect($route->getDomain())->toBe($centralDomains[0])
        ->and($route->gatherMiddleware())->toContain('web');
});

it('runs an addTenantRoutes() callback inside the tenant middleware group', function () {
    Numerosis::addTenantRoutes(function (): void {
        Route::get('/__seam-tenant-probe', fn () => 'ok')->name('seam.tenant.probe');
    });

    Numerosis::routes();

    expect(registeredRoute('seam.tenant.probe')->gatherMiddleware())->toContain('tenant');
});

it('attributes a route contribution to the source that registered it', function () {
    Numerosis::addCentralRoutes(function (): void {}, source: 'nvade/numerosis-onboarding');
    Numerosis::addCentralRoutes(function (): void {});
    Numerosis::addTenantRoutes(function (): void {}, source: 'nvade/numerosis-auth-ui');

    expect(Contributions::centralRouteSources())->toBe(['nvade/numerosis-onboarding', null])
        ->and(Contributions::tenantRouteSources())->toBe(['nvade/numerosis-auth-ui']);
});

it('lets a second package register a Feature without editing config', function () {
    $feature = new class implements Feature
    {
        public static bool $booted = false;

        public function bootstrap(): void
        {
            self::$booted = true;
        }
    };

    Features::register($feature::class);

    expect(Features::all())->toContain($feature::class)
        ->and(Features::enabledClass($feature::class))->toBeTrue();
});

it('does not register the same feature twice', function () {
    $feature = new class implements Feature
    {
        public function bootstrap(): void {}
    };

    $before = count(Features::all());

    Features::register($feature::class);
    Features::register($feature::class);

    expect(Features::all())->toHaveCount($before + 1);
});

it('adds a registered tenant migration path alongside the package own, without dropping either', function () {
    Numerosis::addTenantMigrationPath('/tmp/seam-migrations');

    expect(Numerosis::tenantMigrationPaths())
        ->toContain(Numerosis::tenantMigrationPath())
        ->toContain('/tmp/seam-migrations');

    HostConfig::apply();

    $parameters = Config::array('tenancy.migration_parameters');
    $paths = is_array($parameters['--path'] ?? null) ? $parameters['--path'] : [];

    expect($paths)->toContain('/tmp/seam-migrations');
});

it('adds a registered tenant seeder to what TenantDatabaseSeeder::run() calls', function () {
    Numerosis::addTenantSeeder(Nvade\Numerosis\Database\Seeders\Tenant\UserSeeder::class);

    expect(Numerosis::tenantSeeders())->toBe([
        Nvade\Numerosis\Database\Seeders\Tenant\UserSeeder::class,
    ]);
});

/*
 * Phase 6 (`.claude/plans/archive/memoized-tinkering-meadow.md`) added the two
 * central-side seams below. The motivating case was a satellite owning a
 * permission context whose seeder (`RoleAndPermissionSeeder`) stays in core:
 * a satellite cannot be asked to publish and edit that seeder, and a missing
 * permission context 500s *every* page in the panel, not just its own
 * (`.ai/rules/auth-guards.md`), so "the host can wire it up" is not an
 * acceptable answer. That example was the `modules` context, deleted with the
 * module system in Phase 2 of `.claude/plans/archive/humming-nibbling-flame.md`; the
 * seams stand on their own for the next package that needs one.
 */

it('adds a registered central seeder to what DatabaseSeeder::run() calls', function () {
    Numerosis::addCentralSeeder(Nvade\Numerosis\Database\Seeders\PaymentPlanSeeder::class);

    expect(Numerosis::centralSeeders())->toBe([
        Nvade\Numerosis\Database\Seeders\PaymentPlanSeeder::class,
    ]);
});

it('seeds a contributed permission context under the central guard and grants it to admin', function () {
    Numerosis::addPermissionContext('seam_probe');

    seed(Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder::class);

    $central = Config::string('tenancy.database.central_connection', 'central');

    // Asserted on the central connection explicitly: these rows are written
    // through it (autocommit) and are invisible to the default connection's
    // open RefreshDatabase transaction — see .ai/rules/testing.md.
    foreach (Nvade\Numerosis\Models\Permission::defaultActions() as $action) {
        assertDatabaseHas('permissions', [
            'name' => $action.' seam_probe',
            'guard_name' => 'web',
        ], $central);
    }

    $admin = Nvade\Numerosis\Models\Role::on($central)
        ->where(['name' => 'admin', 'guard_name' => 'web'])
        ->sole();

    expect($admin->hasPermissionTo('viewAny seam_probe'))->toBeTrue();
});

/**
 * Every list on `Contributions` is a plain static, so it outlives the request
 * that filled it. A provider that registers twice — Octane booting a worker,
 * a host provider re-registered by a test harness — used to grow them without
 * bound, which reaches `HostConfig` as a duplicated `--path` in
 * `tenancy.migration_parameters` and a repeated seeder class. Registering the
 * same value twice has to be a no-op.
 *
 * Route callbacks are deliberately not covered: two `Closure`s built from the
 * same `function () { … }` on two boots are distinct objects with nothing
 * comparable about them. See `Contributions::appendOnce()`.
 */
it('does not accumulate a contribution registered twice', function () {
    $path = Numerosis::tenantMigrationPath();

    Numerosis::addTenantMigrationPath($path);
    Numerosis::addTenantMigrationPath($path);

    Numerosis::addTenantColumns(['duplicated_column']);
    Numerosis::addTenantColumns(['duplicated_column']);

    Numerosis::addPermissionContext('duplicated_context');
    Numerosis::addPermissionContext('duplicated_context');

    Numerosis::addCentralSeeder(Nvade\Numerosis\Database\Seeders\PaymentPlanSeeder::class);
    Numerosis::addCentralSeeder(Nvade\Numerosis\Database\Seeders\PaymentPlanSeeder::class);

    expect(Contributions::tenantMigrationPaths())->toBe([$path])
        ->and(Contributions::tenantColumns())->toBe(['duplicated_column'])
        ->and(Contributions::permissionContexts())->toBe(['duplicated_context'])
        ->and(Contributions::centralSeeders())->toBe([
            Nvade\Numerosis\Database\Seeders\PaymentPlanSeeder::class,
        ]);
});

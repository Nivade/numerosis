<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Contracts\Feature;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\HostConfig;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\Numerosis\Support\Tenancy\TenancyConfigKeys;

/*
 * Phase 3 of .claude/plans/memoized-tinkering-meadow.md: additive
 * contribution seams a second package uses to add a route, a feature, or a
 * tenant migration path/seeder without reproducing (or replacing) the
 * package's own registration. Each of these is a static that persists for
 * the process lifetime — same as Numerosis::$registerRoutesCallback — so
 * every test resets it, or a contribution here leaks into an unrelated,
 * later test.
 */

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

    // getByName() reads a lookup table normally rebuilt by
    // RouteServiceProvider after loading a route file — a bypass this
    // test's direct Numerosis::routes() call never goes through, so a
    // fresh route added here has to be found by scanning the collection.
    $route = collect(Route::getRoutes()->getRoutes())
        ->sole(fn ($r) => $r->getName() === 'seam.central.probe');
    $centralDomains = Config::array(TenancyConfigKeys::key('central_domains'));

    expect($route->getDomain())->toBe($centralDomains[0])
        ->and($route->gatherMiddleware())->toContain('web');
});

it('runs an addTenantRoutes() callback inside the tenant middleware group', function () {
    Numerosis::addTenantRoutes(function (): void {
        Route::get('/__seam-tenant-probe', fn () => 'ok')->name('seam.tenant.probe');
    });

    Numerosis::routes();

    $route = collect(Route::getRoutes()->getRoutes())
        ->sole(fn ($r) => $r->getName() === 'seam.tenant.probe');

    expect($route->gatherMiddleware())->toContain('tenant');
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

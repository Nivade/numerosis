<?php

declare(strict_types=1);

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Nvade\Numerosis\Contracts\Feature;
use Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\HostConfig;
use Nvade\Numerosis\Support\Numerosis;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\seed;

/*
 * `Support\Contributions` and its ten `Numerosis::add*()` delegators are gone
 * (`.claude/plans/effervescent-questing-pumpkin.md`). What replaced each
 * registry is asserted here: the container for seeders and permission
 * contexts, conventional paths for routes and tenant migrations, schema
 * introspection for tenant columns (`TenantColumnsTest`).
 *
 * `Features::register()` is a different seam and stays.
 */

afterEach(function (): void {
    Features::resetRegisteredForTesting();
});

it('keeps the host conventional tenant migration directory alongside the package own', function () {
    Config::set('tenancy.migration_parameters', []);

    HostConfig::apply();

    $parameters = Config::array('tenancy.migration_parameters');
    $paths = is_array($parameters['--path'] ?? null) ? $parameters['--path'] : [];

    expect($paths)
        ->toContain(database_path('migrations/tenant'))
        ->toContain(Numerosis::tenantMigrationPath())
        ->and($parameters['--realpath'] ?? null)->toBeTrue();
});

it('leaves a non conventional tenant migration path configured by the host alone', function () {
    Config::set('tenancy.migration_parameters', ['--path' => ['/tmp/host-migrations']]);

    HostConfig::apply();

    $parameters = Config::array('tenancy.migration_parameters');
    $paths = is_array($parameters['--path'] ?? null) ? $parameters['--path'] : [];

    expect($paths)->toContain('/tmp/host-migrations')
        ->and($paths)->toContain(Numerosis::tenantMigrationPath())
        ->and(in_array(database_path('migrations/tenant'), $paths, true))->toBeFalse();
});

/*
 * `numerosis:install` publishes both of these, and nothing else asserts the
 * groups exist — a typo'd tag reports "No publishable resources" and exits 0.
 */
it('publishes an empty routes/tenant.php and the tenant seeder', function () {
    $normalise = function (string $tag): array {
        /** @var array<string, string> $paths */
        $paths = ServiceProvider::$publishGroups[$tag] ?? [];
        $normalised = [];

        foreach ($paths as $source => $target) {
            $normalised[(string) realpath($source)] = $target;
        }

        return $normalised;
    };

    $package = dirname(__DIR__, 3);

    expect($normalise('numerosis-routes'))->toBe([
        $package.'/stubs/routes/tenant.php' => base_path('routes/tenant.php'),
    ])->and($normalise('numerosis-seeders'))->toBe([
        $package.'/database/seeders/TenantDatabaseSeeder.php' => database_path('seeders/TenantDatabaseSeeder.php'),
    ]);
});

it('resolves a seeder the package calls through the container, so a binding swaps it', function () {
    $replacement = new class extends Seeder
    {
        public static bool $ran = false;

        public function run(): void
        {
            self::$ran = true;
        }
    };

    app()->bind(Nvade\Numerosis\Database\Seeders\PaymentPlanSeeder::class, $replacement::class);

    seed(Nvade\Numerosis\Database\Seeders\DatabaseSeeder::class);

    expect($replacement::$ran)->toBeTrue();
});

it('seeds a permission context added by a subclass bound over the package seeder', function () {
    $replacement = new class extends RoleAndPermissionSeeder
    {
        /**
         * @return list<string>
         */
        protected function contexts(): array
        {
            return [...parent::contexts(), 'seam_probe'];
        }
    };

    app()->bind(RoleAndPermissionSeeder::class, $replacement::class);

    seed(RoleAndPermissionSeeder::class);

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

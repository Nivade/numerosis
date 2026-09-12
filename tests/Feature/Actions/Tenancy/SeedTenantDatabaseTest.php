<?php

declare(strict_types=1);

use App\Models\Central\TenantProvision;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Tenancy\CreateTenant;
use Nvade\Numerosis\Actions\Tenancy\CreateTenantDatabase;
use Nvade\Numerosis\Actions\Tenancy\MigrateTenantDatabase;
use Nvade\Numerosis\Actions\Tenancy\SeedTenantDatabase;
use Nvade\Numerosis\Database\Seeders\TenantDatabaseSeeder;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Models\Role;
use Nvade\Numerosis\Tests\Support\TestTenant;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The real migrate step, not CloneTenantSchema: the clone seeds as well,
    // and this file's subject is what seeding does to an unseeded database.
    Config::set('numerosis.tenancy.provisioning.steps', [
        CreateTenant::class,
        CreateTenantDatabase::class,
        MigrateTenantDatabase::class,
    ]);
});

function seedProvisionFor(BaseTenant $tenant): TenantProvision
{
    /** @var TenantProvision */
    return TenantProvision::query()->updateOrCreate(
        ['slug' => (string) $tenant->getTenantKey()],
        ['name' => 'Seed Co', 'global_id' => 'seed-global-id'],
    );
}

it('throws when the seeder fails', function () {
    $tenant = TestTenant::provisioned();

    // Overriding the binding, not mocking Artisan: the step resolves
    // TenantDatabaseSeeder straight from the container — see its own
    // docblock for why it no longer goes through Artisan::call() at all.
    app()->bind(TenantDatabaseSeeder::class, fn () => new class extends Seeder
    {
        public function run(): never
        {
            throw new RuntimeException('seeder blew up');
        }
    });

    $provision = seedProvisionFor($tenant);

    expect(fn () => SeedTenantDatabase::run($provision))
        ->toThrow(RuntimeException::class, 'seeder blew up');

    // Reverted in a finally: leaving tenancy initialized would leak into
    // whatever the worker picks up next.
    expect(tenancy()->initialized)->toBeFalse();
});

/**
 * A `PDOException` carries a SQLSTATE string in `getCode()`, and
 * `RuntimeException` only accepts an int, so re-throwing with the original
 * code turned every database-caused seeding failure into a `TypeError` raised
 * from the catch block -- losing the real cause entirely. Found running the
 * chain against a real queue worker; the suite never saw it because every
 * fixture threw with the default code of 0.
 */
it('reports the real failure when the seeder throws a string exception code', function () {
    $tenant = TestTenant::provisioned();

    // `$code` is only a string once a driver has set it, so constructing a
    // bare PDOException leaves it at the default int 0 and proves nothing.
    $sqlstate = new class('SQLSTATE[HY000]: unknown function') extends PDOException
    {
        public function __construct(string $message)
        {
            parent::__construct($message);

            $this->code = 'HY000';
        }
    };

    app()->bind(TenantDatabaseSeeder::class, fn () => new class($sqlstate) extends Seeder
    {
        public function __construct(private PDOException $sqlstate) {}

        public function run(): never
        {
            throw $this->sqlstate;
        }
    });

    expect(fn () => SeedTenantDatabase::run(seedProvisionFor($tenant)))
        ->toThrow(RuntimeException::class, 'unknown function');
});

it('resolves the seeder class configured via numerosis.tenancy.seeder', function () {
    $tenant = TestTenant::provisioned();

    $spySeeder = new class extends Seeder
    {
        public bool $ran = false;

        public function run(): void
        {
            $this->ran = true;
        }
    };

    app()->bind($spySeeder::class, fn () => $spySeeder);
    Config::set('numerosis.tenancy.seeder', $spySeeder::class);

    SeedTenantDatabase::run(seedProvisionFor($tenant));

    expect($spySeeder->ran)->toBeTrue();
});

it('seeds the tenant database on success', function () {
    $tenant = TestTenant::provisioned();

    SeedTenantDatabase::run(seedProvisionFor($tenant));

    $roleExists = $tenant->run(fn (): bool => Role::where('name', 'admin')->where('guard_name', 'tenant')->exists());

    expect($roleExists)->toBeTrue();
    expect(tenancy()->initialized)->toBeFalse();
});

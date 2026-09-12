<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use App\Models\Central\TenantProvision;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Tenancy\SeedTenantDatabase;
use Nvade\Numerosis\Database\Seeders\TenantDatabaseSeeder;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Models\Role;

uses(RefreshDatabase::class);

function seedProvisionFor(BaseTenant $tenant): TenantProvision
{
    /** @var TenantProvision */
    return TenantProvision::query()->forceCreate([
        'slug' => (string) $tenant->getTenantKey(),
        'name' => 'Seed Co',
        'global_id' => 'seed-global-id',
    ]);
}

it('throws when the seeder fails', function () {
    $tenant = Tenant::factory()->create();

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

it('seeds the tenant database on success', function () {
    $tenant = Tenant::factory()->create();

    SeedTenantDatabase::run(seedProvisionFor($tenant));

    $roleExists = $tenant->run(fn (): bool => Role::where('name', 'admin')->where('guard_name', 'tenant')->exists());

    expect($roleExists)->toBeTrue();
    expect(tenancy()->initialized)->toBeFalse();
});

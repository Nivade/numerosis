<?php

declare(strict_types=1);

use App\Models\Central\PendingTenantProvision;
use App\Models\Central\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Database\Seeders\TenantDatabaseSeeder;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Jobs\SeedTenantDatabase;
use Nvade\Numerosis\Models\Role;

uses(RefreshDatabase::class);

it('throws when the seeder fails', function () {
    $tenant = Tenant::factory()->create();

    // Overriding the binding, not mocking Artisan: the job resolves
    // TenantDatabaseSeeder straight from the container — see the job's own
    // docblock for why it no longer goes through Artisan::call() at all.
    app()->bind(TenantDatabaseSeeder::class, fn () => new class extends Seeder
    {
        public function run(): never
        {
            throw new RuntimeException('seeder blew up');
        }
    });

    $job = new SeedTenantDatabase($tenant);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'seeder blew up');

    expect(tenancy()->initialized)->toBeFalse();
});

it('seeds the tenant database on success', function () {
    $tenant = Tenant::factory()->create();

    $job = new SeedTenantDatabase($tenant);
    $job->handle();

    $roleExists = $tenant->run(fn (): bool => Role::where('name', 'admin')->where('guard_name', 'tenant')->exists());

    expect($roleExists)->toBeTrue();
    expect(tenancy()->initialized)->toBeFalse();
});

it('marks the pending provision as failed when the job fails', function () {
    $domain = 'test-tenant-'.uniqid();
    $tenant = Tenant::forceCreate(['id' => $domain]);

    PendingTenantProvision::create([
        'domain' => $domain,
        'company_name' => 'Acme',
        'global_id' => 'user-global-id',
        'status' => TenantProvisionStatus::Provisioning,
    ]);

    $job = new SeedTenantDatabase($tenant);
    $job->failed(new RuntimeException('Seeding failed for tenant '.$domain));

    $pending = PendingTenantProvision::where('domain', $domain)->firstOrFail();

    expect($pending->status)->toBe(TenantProvisionStatus::Failed)
        ->and($pending->error)->toContain('Seeding failed');
});

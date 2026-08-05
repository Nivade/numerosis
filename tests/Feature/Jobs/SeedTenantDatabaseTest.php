<?php

declare(strict_types=1);

use App\Models\Central\PendingTenantProvision;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Nvade\Numerosis\Enums\TenantProvisionStatus;
use Nvade\Numerosis\Jobs\SeedTenantDatabase;

uses(RefreshDatabase::class);

it('throws when tenants:seed exits non-zero', function () {
    $tenant = Tenant::forceCreate(['id' => 'test-tenant-'.uniqid()]);

    Artisan::shouldReceive('call')
        ->once()
        ->andReturn(1);
    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('seeder blew up');

    $job = new SeedTenantDatabase($tenant);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'seeder blew up');
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
    $job->failed(new RuntimeException('tenants:seed failed for tenant '.$domain));

    $pending = PendingTenantProvision::where('domain', $domain)->firstOrFail();

    expect($pending->status)->toBe(TenantProvisionStatus::Failed)
        ->and($pending->error)->toContain('tenants:seed failed');
});

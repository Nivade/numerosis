<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use App\Models\Central\TenantProvision;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Nvade\Numerosis\Actions\Tenancy\FinalizeTenantProvisioning;
use Nvade\Numerosis\Models\Role;

uses(RefreshDatabase::class);

/**
 * FinalizeTenantProvisioning takes the provision row like every other step;
 * these cases care about the tenant, not the row.
 */
function provisionRowFor(Tenant $tenant): TenantProvision
{
    /** @var TenantProvision */
    return TenantProvision::query()->forceCreate([
        'slug' => (string) $tenant->getTenantKey(),
        'name' => 'Finalize Co',
        'global_id' => 'finalize-global-id',
    ]);
}

it('assigns admin role to the first non-bot user and ignores bots', function () {
    // 1. Arrange: Create a tenant
    $tenant = Tenant::create([
        'id' => 'test-tenant-'.uniqid(),
    ]);

    // Run migrations for the tenant
    Artisan::call('tenants:migrate', ['--tenants' => [$tenant->id]]);

    $tenant->run(function () use ($tenant) {
        // Create the 'admin' role in tenant context
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'tenant']);

        // 2. Create a bot user first
        $bot = TenantUser::create([
            'name' => 'Bot User',
            'email' => 'bot@example.com',
            'password' => bcrypt('password'),
            'global_id' => 'bot-id',
            'is_bot' => true,
        ]);

        // 3. Create a regular user second
        $user = TenantUser::create([
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'global_id' => 'user-id',
            'is_bot' => false,
        ]);

        // 4. Act: Run the action
        FinalizeTenantProvisioning::run(provisionRowFor($tenant));

        // 5. Assert
        expect($bot->refresh()->hasRole('admin', 'tenant'))->toBeFalse();
        expect($user->refresh()->hasRole('admin', 'tenant'))->toBeTrue();
    });
});

it('fails if only bots exist', function () {
    $tenant = Tenant::create([
        'id' => 'bot-only-tenant-'.uniqid(),
    ]);

    Artisan::call('tenants:migrate', ['--tenants' => [$tenant->id]]);

    $tenant->run(function () use ($tenant) {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'tenant']);

        TenantUser::create([
            'name' => 'Bot User',
            'email' => 'bot@example.com',
            'password' => bcrypt('password'),
            'global_id' => 'bot-id-2',
            'is_bot' => true,
        ]);

        try {
            FinalizeTenantProvisioning::run(provisionRowFor($tenant));
            $this->fail('Job should have failed when only bots are present');
        } catch (Throwable $e) {
            expect($e->getMessage())->toContain('No non-bot users found');
        }
    });
});

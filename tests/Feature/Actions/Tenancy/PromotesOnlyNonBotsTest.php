<?php

declare(strict_types=1);

use App\Models\Central\TenantProvision;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Tenancy\PromoteFirstUserToAdmin;
use Nvade\Numerosis\Models\Role;
use Nvade\Numerosis\Tests\Support\TestTenant;

uses(RefreshDatabase::class);

/**
 * Promotion is its own step since owners became a contribution, so these moved
 * off `FinalizeTenantProvisioning` with it. They take the provision row like
 * every other step, but care about the tenant's users rather than the row.
 */
function provisionRowFor(BaseTenant $tenant): TenantProvision
{
    /** @var TenantProvision */
    return TenantProvision::query()->updateOrCreate(
        ['slug' => (string) $tenant->getTenantKey()],
        ['name' => 'Promote Co', 'global_id' => 'promote-global-id'],
    );
}

it('assigns admin role to the first non-bot user and ignores bots', function () {
    // 1. Arrange: Create a tenant
    $tenant = TestTenant::withDatabaseOnly(['id' => 'test-tenant-'.uniqid()]);

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
        PromoteFirstUserToAdmin::run(provisionRowFor($tenant));

        // 5. Assert
        expect($bot->refresh()->hasRole('admin', 'tenant'))->toBeFalse();
        expect($user->refresh()->hasRole('admin', 'tenant'))->toBeTrue();
    });
});

it('fails if only bots exist', function () {
    $tenant = TestTenant::withDatabaseOnly(['id' => 'bot-only-tenant-'.uniqid()]);

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
            PromoteFirstUserToAdmin::run(provisionRowFor($tenant));
            $this->fail('Promotion should have failed when only bots are present');
        } catch (Throwable $e) {
            expect($e->getMessage())->toContain('No non-bot users found');
        }
    });
});

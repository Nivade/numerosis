<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Nvade\Numerosis\Actions\Auth\UpdateUserPassword;
use Nvade\Numerosis\Actions\Auth\UpdateUserProfile;
use Nvade\Numerosis\Actions\Tenancy\AddTenantOwner;
use Nvade\Numerosis\Tests\Concerns\BuildsTenantProvisionData;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Editing a tenant user writes through to the central row, via stancl's
 * `Syncable` machinery.
 *
 * Drives the two core actions directly rather than a settings screen: the
 * sync is what this covers, and it happens on `save()` regardless of which
 * surface called it. The screens that call them are covered by their own
 * component tests.
 */
class ProfileSyncTest extends TestCase
{
    use BuildsTenantProvisionData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure we are using synchronous queue for syncing
        config(['queue.default' => 'sync']);
    }

    public function test_profile_update_syncs_to_central(): void
    {
        $tenant = Tenant::factory()->create([
            'id' => 'test'.str_replace('.', '', uniqid('', true)),
        ]);

        $centralUser = CentralUser::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'global_id' => 'global-1',
        ]);

        AddTenantOwner::run($tenant, $this->ownerProvisionData($tenant, $centralUser));

        $tenant->run(function () {
            $tenantUser = TenantUser::where('global_id', 'global-1')->first();
            $this->assertNotNull($tenantUser, 'Tenant user was not created/synced');

            $this->actingAsTenantUser($tenantUser);

            // Pre-condition: Tenant user should exist and have same data
            $this->assertEquals('Old Name', $tenantUser->name);

            UpdateUserProfile::run($tenantUser, [
                'name' => 'New Name',
                'email' => 'new@example.com',
            ]);

            // Verify tenant DB updated
            $tenantUser->refresh();
            $this->assertEquals('New Name', $tenantUser->name);
            $this->assertEquals('new@example.com', $tenantUser->email);
        });

        // Verify central DB updated (Syncing should have happened)
        $centralUser->refresh();
        $this->assertEquals('New Name', $centralUser->name);
        $this->assertEquals('new@example.com', $centralUser->email);
    }

    public function test_password_update_syncs_to_central(): void
    {
        $tenant = Tenant::factory()->create([
            'id' => 'test'.str_replace('.', '', uniqid('', true)),
        ]);

        $centralUser = CentralUser::factory()->create([
            'password' => Hash::make('old-password'),
            'global_id' => 'global-2',
        ]);

        AddTenantOwner::run($tenant, $this->ownerProvisionData($tenant, $centralUser));

        $tenant->run(function () {
            $tenantUser = TenantUser::where('global_id', 'global-2')->first();
            $this->assertNotNull($tenantUser, 'Tenant user was not created/synced');

            $this->actingAsTenantUser($tenantUser);

            UpdateUserPassword::run($tenantUser, [
                'current_password' => 'old-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

            // Verify tenant DB updated
            $tenantUser->refresh();
            $this->assertTrue(Hash::check('new-password', $tenantUser->password));
        });

        // Verify central DB updated
        $centralUser->refresh();
        $this->assertTrue(Hash::check('new-password', $centralUser->password));
    }
}

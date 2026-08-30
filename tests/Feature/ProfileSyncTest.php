<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Tenancy\AddTenantOwner;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Profile\Pages\General;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Profile\Pages\Security;
use Nvade\Numerosis\Tests\TestCase;

class ProfileSyncTest extends TestCase
{
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

        AddTenantOwner::run($tenant, $this->provisionData($tenant, $centralUser));

        $tenant->run(function () use ($tenant) {
            $tenantUser = TenantUser::where('global_id', 'global-1')->first();
            $this->assertNotNull($tenantUser, 'Tenant user was not created/synced');

            $this->actingAsTenantPanelUser($tenant, $tenantUser);

            // Pre-condition: Tenant user should exist and have same data
            $this->assertEquals('Old Name', $tenantUser->name);

            Livewire::test(General::class)
                ->set('data.name', 'New Name')
                ->set('data.email', 'new@example.com')
                ->call('save')
                ->assertHasNoFormErrors();

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

        AddTenantOwner::run($tenant, $this->provisionData($tenant, $centralUser));

        $tenant->run(function () use ($tenant) {
            $tenantUser = TenantUser::where('global_id', 'global-2')->first();
            $this->assertNotNull($tenantUser, 'Tenant user was not created/synced');

            $this->actingAsTenantPanelUser($tenant, $tenantUser);

            Livewire::test(Security::class)
                ->set('data.current_password', 'old-password')
                ->set('data.password', 'new-password')
                ->set('data.password_confirmation', 'new-password')
                ->call('save')
                ->assertHasNoFormErrors();

            // Verify tenant DB updated
            $tenantUser->refresh();
            $this->assertTrue(Hash::check('new-password', $tenantUser->password));
        });

        // Verify central DB updated
        $centralUser->refresh();
        $this->assertTrue(Hash::check('new-password', $centralUser->password));
    }

    private function provisionData(Tenant $tenant, CentralUser $user): TenantProvisionData
    {
        return new TenantProvisionData(
            registration: TenantRegistrationData::from([
                'company_name' => 'Test Co',
                'domain' => (string) $tenant->getTenantKey(),
                'global_id' => $user->global_id,
            ]),
        );
    }
}

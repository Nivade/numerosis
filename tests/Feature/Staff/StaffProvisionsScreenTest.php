<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Staff;

use App\Models\Central\CentralUser;
use App\Models\Central\TenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder;
use Nvade\Numerosis\Features\Admin\StaffPanelFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Tests\TestCase;

class StaffProvisionsScreenTest extends TestCase
{
    use RefreshDatabase;

    private bool $permissionsSeeded = false;

    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([StaffPanelFeature::class]);

        parent::setUp();
    }

    public function test_retrying_requeues_the_chain_and_is_logged(): void
    {
        $provision = TenantProvision::factory()->failed()->create(['slug' => 'retryable', 'name' => 'Retryable Co']);

        $provisioning = Mockery::mock(ProvisionsTenant::class);
        $provisioning->shouldReceive('queue')
            ->once()
            ->with(Mockery::on(fn (TenantProvisionData $data): bool => $data->slug === $provision->slug));

        $this->instance(ProvisionsTenant::class, $provisioning);

        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test('numerosis-pages::staff.provisions')
            ->call('retry', $provision->slug);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'Provisioning for retryable retried by staff',
            'causer_id' => $admin->getKey(),
        ]);
    }

    public function test_cancelling_deletes_the_reservation_and_is_logged(): void
    {
        $provision = TenantProvision::factory()->create(['slug' => 'cancelme', 'name' => 'Cancel Co']);

        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test('numerosis-pages::staff.provisions')
            ->call('cancel', $provision->slug);

        $this->assertDatabaseMissing('tenant_provisions', ['slug' => 'cancelme'], 'central');

        $this->assertDatabaseHas('activity_log', [
            'description' => 'Provisioning for cancelme cancelled by staff',
            'causer_id' => $admin->getKey(),
        ]);
    }

    public function test_a_user_without_permissions_cannot_cancel(): void
    {
        TenantProvision::factory()->create(['slug' => 'protected', 'name' => 'Protected Co']);

        Livewire::actingAs($this->userWithoutPermissions())
            ->test('numerosis-pages::staff.provisions')
            ->call('cancel', 'protected')
            ->assertForbidden();

        $this->assertDatabaseHas('tenant_provisions', ['slug' => 'protected'], 'central');
    }

    private function admin(): BaseCentralUser
    {
        $admin = $this->userWithoutPermissions();
        $admin->assignRole('admin');

        return $admin;
    }

    /** The decoy covers `CentralUserObserver`'s promotion of the first user. */
    private function userWithoutPermissions(): BaseCentralUser
    {
        $this->seedPermissionsOnce();

        CentralUser::factory()->create();

        return CentralUser::factory()->create();
    }

    /**
     * Once per test: every central role and permission row is written through
     * the `central` connection, which is autocommit, so re-seeding inside one
     * test leaves more rows for teardown to chase than it needs to.
     */
    private function seedPermissionsOnce(): void
    {
        if ($this->permissionsSeeded) {
            return;
        }

        $this->permissionsSeeded = true;

        (new RoleAndPermissionSeeder)->run();
    }
}

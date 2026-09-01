<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Tenancy;

use App\Models\Central\CentralUser;
use App\Models\Central\PendingTenantProvision;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Actions\Tenancy\FinalizeTenantProvisioning;
use Nvade\Numerosis\Actions\Tenancy\ProvisionTenant;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioned;
use Nvade\Numerosis\Tests\TestCase;
use RuntimeException;

/**
 * FinalizeTenantProvisioning is the only thing that reports provisioning as
 * finished, so these cover the signal itself rather than the role assignment
 * (which FinalizeTenantProvisioningTest already covers).
 */
class TenantProvisioningSignalTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stamps_provisioned_at_and_clears_the_pending_row(): void
    {
        $user = CentralUser::factory()->create();

        PendingTenantProvision::create([
            'domain' => 'signaltenant',
            'company_name' => 'Signal Co',
            'global_id' => $user->global_id,
            'status' => TenantProvisionStatus::Provisioning,
        ]);

        ProvisionTenant::run($this->provisionData($user, 'signaltenant'));

        $tenant = Tenant::find('signaltenant');

        $this->assertNotNull($tenant->provisioned_at);
        $this->assertNull(PendingTenantProvision::find('signaltenant'));
    }

    public function test_it_broadcasts_to_the_owner_when_provisioning_finishes(): void
    {
        Event::fake([TenantProvisioned::class]);

        $user = CentralUser::factory()->create();

        ProvisionTenant::run($this->provisionData($user, 'broadcasttenant'));

        Event::assertDispatched(fn (TenantProvisioned $event) => $event->tenant->id === 'broadcasttenant'
            && (int) $event->ownerId === $user->id);
    }

    public function test_it_broadcasts_on_the_owners_private_channel(): void
    {
        $user = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create();

        $channels = new TenantProvisioned($tenant, $user->id)->broadcastOn();

        $this->assertSame("private-user.{$user->id}", $channels[0]->name);
    }

    public function test_a_tenant_is_not_ready_until_provisioned_at_is_set(): void
    {
        CentralUser::factory()->create();
        $tenant = Tenant::factory()->create(['provisioned_at' => null]);

        // Mirrors how tenants.mine splits ready from still-provisioning: the
        // existence of the tenant row alone must never count as ready.
        $this->assertTrue(Tenant::whereNull('provisioned_at')->where('id', $tenant->id)->exists());
        $this->assertFalse(Tenant::whereNotNull('provisioned_at')->where('id', $tenant->id)->exists());
    }

    public function test_it_marks_the_pending_row_failed_when_the_job_fails(): void
    {
        $user = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create();

        PendingTenantProvision::create([
            'domain' => $tenant->id,
            'company_name' => 'Broken Co',
            'global_id' => $user->global_id,
            'status' => TenantProvisionStatus::Provisioning,
        ]);

        (new FinalizeTenantProvisioning)->jobFailed(new RuntimeException('seeding blew up'), $tenant);

        $pending = PendingTenantProvision::find($tenant->id);

        $this->assertSame(TenantProvisionStatus::Failed, $pending->status);
        $this->assertSame('seeding blew up', $pending->error);
        $this->assertNotNull($pending->failed_at);
    }

    private function provisionData(CentralUser $user, string $domain): TenantProvisionData
    {
        return new TenantProvisionData(
            registration: TenantRegistrationData::from([
                'company_name' => 'Test Company',
                'domain' => $domain,
                'billing_cycle' => BillingCycle::Monthly,
                'global_id' => $user->global_id,
            ]),
            centralUserId: (string) $user->id,
        );
    }
}

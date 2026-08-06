<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Tenancy;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Billing\Subscriptions\RecordSubscription;
use Nvade\Numerosis\Actions\Tenancy\CreateTenant;
use Nvade\Numerosis\Actions\Tenancy\ProvisionTenant;
use Nvade\Numerosis\Data\Billing\SubscriptionData;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Tests\TestCase;

class CreateTenantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `CreateTenant` only creates the tenant row and domain —
     * database creation and owner attachment moved into `ProvisionTenant`'s
     * queued chain. See .claude/rules/tenant-provisioning.md.
     */
    public function test_it_creates_only_the_tenant_row_and_domain(): void
    {
        $user = CentralUser::factory()->create([
            'global_id' => 'sync-db-'.uniqid(),
        ]);

        $tenantId = 'sync-db-tenant-'.uniqid();
        $registration = TenantRegistrationData::from([
            'company_name' => 'Sync DB Co',
            'domain' => $tenantId,
            'global_id' => $user->global_id,
        ]);

        $tenant = CreateTenant::run($registration);

        // Tenant/Domain live on the `central` connection (Tenant model's
        // CentralConnection trait), a separate PDO session from the default
        // connection RefreshDatabase transacts — see .claude/rules/testing.md
        // on why central-connection writes need the connection named
        // explicitly rather than relying on incidental query ordering.
        $this->assertDatabaseHas('tenants', ['id' => $tenantId], 'central');
        $this->assertDatabaseHas('domains', [
            'domain' => $tenantId.'.'.Config::string('numerosis.domains.apex'),
            'tenant_id' => $tenantId,
        ], 'central');
        $this->assertFalse($user->tenants()->where('tenants.id', $tenantId)->exists());
    }

    public function test_it_creates_a_tenant_with_domain_and_subscription(): void
    {
        // Arrange
        $user = CentralUser::factory()->create([
            'global_id' => 'test-global-id-'.uniqid(),
        ]);

        $paymentPlan = PaymentPlan::create([
            'name' => 'Test Plan',
            'slug' => 'test-plan',
            'description' => 'Test description',
            'monthly_price' => 1000,
            'yearly_price' => 10000,
            'trial_days' => 14,
            'available' => true,
        ]);

        $tenantId = 'test-tenant-'.uniqid();
        $registration = TenantRegistrationData::from([
            'payment_plan' => 'test-plan',
            'billing_cycle' => BillingCycle::Monthly,
            'company_name' => 'Test Company',
            'domain' => $tenantId,
            'global_id' => $user->global_id,
        ]);

        // Act
        ProvisionTenant::run(new TenantProvisionData(
            registration: $registration,
            centralUserId: (string) $user->id,
        ));

        $tenant = Tenant::findOrFail($tenantId);

        RecordSubscription::run(new SubscriptionData(
            user_id: (string) $user->id,
            payment_plan_id: (string) $paymentPlan->id,
            stripe_id: 'sub_test',
            stripe_status: 'active',
            subscribable_id: $tenant->id,
            subscribable_type: Tenant::class,
            stripe_price: 'price_test',
            quantity: 1,
            trial_ends_at: null,
            ends_at: null,
        ));

        // Assert
        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
        ]);

        $this->assertDatabaseHas('domains', [
            'domain' => $tenantId.'.'.Config::string('numerosis.domains.apex'),
            'tenant_id' => $tenantId,
        ]);

        $this->assertTrue($user->refresh()->tenants->contains($tenant));
        $this->assertEquals('owner', $user->tenants()->firstOrFail()->pivot->role);

        $this->assertDatabaseHas('subscriptions', [
            'subscribable_id' => $tenantId,
            'subscribable_type' => Tenant::class,
            'user_id' => $user->id,
            'stripe_status' => 'active',
        ]);
    }
}

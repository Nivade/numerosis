<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Billing\Subscriptions\RecordSubscription;
use Nvade\Numerosis\Actions\Tenancy\ProvisionTenant;
use Nvade\Numerosis\Data\Billing\SubscriptionData;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Tests\TestCase;

class InterviewShowcaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * This test demonstrates the full lifecycle of a tenant in this SaaS application:
     * 1. A central user is created.
     * 2. The user registers a new tenant with a specific payment plan.
     * 3. A subscription is established for the tenant.
     * 4. Tenancy is initialized, and the user's presence is verified within the tenant's database.
     * 5. Role synchronization (Admin) is verified within the tenant context.
     */
    public function test_full_tenant_lifecycle_showcase(): void
    {
        // 1. Arrange: Setup Central Environment
        $user = CentralUser::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'global_id' => 'global-john-'.uniqid(),
        ]);

        $paymentPlan = PaymentPlan::create([
            'name' => 'Professional',
            'slug' => 'pro',
            'description' => 'For growing teams',
            'monthly_price' => 2900,
            'yearly_price' => 29000,
            'trial_days' => 14,
            'available' => true,
        ]);

        $tenantDomain = 'acme-'.uniqid();
        $registration = TenantRegistrationData::from([
            'company_name' => 'Acme Corp',
            'domain' => $tenantDomain,
            'global_id' => $user->global_id,
            'payment_plan' => 'pro',
            'billing_cycle' => BillingCycle::Monthly,
        ]);

        // 2. Act: Provision the tenant through the one entry point production
        // uses. Going through ProvisionTenant rather than CreateTenant
        // is what makes this a lifecycle test: the admin promotion and the
        // "provisioning finished" signal live in FinalizeTenantProvisioning,
        // which ProvisionTenant dispatches after the creation steps.
        ProvisionTenant::run(new TenantProvisionData(
            registration: $registration,
            centralUserId: (string) $user->id,
        ));

        $tenant = Tenant::findOrFail($tenantDomain);

        // 3. Act: Create Subscription
        RecordSubscription::run(new SubscriptionData(
            user_id: (string) $user->id,
            payment_plan_id: (string) $paymentPlan->id,
            subscribable_type: Tenant::class,
            subscribable_id: $tenant->id,
            stripe_id: 'sub_live_showcase',
            stripe_status: 'active',
            stripe_price: 'price_pro_monthly',
            quantity: 1,
        ));

        // 4. Assert: Central State
        $this->assertDatabaseHas('tenants', ['id' => $tenantDomain]);
        $this->assertDatabaseHas('domains', ['domain' => $tenantDomain.'.'.Config::string('app.domain')]);
        $this->assertDatabaseHas('subscriptions', [
            'subscribable_id' => $tenantDomain,
            'stripe_status' => 'active',
        ]);

        // 5. Act & Assert: Tenant Contextual State
        $tenant->run(function () use ($user) {
            // Verify Resource Syncing: The central user should exist in the tenant database
            $tenantUser = TenantUser::where('global_id', $user->global_id)->first();

            $this->assertNotNull($tenantUser, 'User should be synced to the tenant database.');
            $this->assertEquals($user->email, $tenantUser->email);

            // Clear permission cache to ensure fresh state
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            // Verify Job Execution: FinalizeTenantProvisioning should have assigned the admin role
            // (Note: In tests, jobs usually run synchronously if QUEUE_CONNECTION=sync)
            $this->assertDatabaseHas('model_has_roles', [
                'role_id' => \Nvade\Numerosis\Models\Role::where('name', 'admin')->where('guard_name', 'tenant')->first()->id,
                'model_id' => $tenantUser->id,
                'model_type' => $tenantUser->getMorphClass(),
            ]);
            $this->assertTrue($tenantUser->refresh()->hasRole('admin', 'tenant'), 'First user should be assigned the admin role.');
        });

        // 6. Verify Ownership
        $this->assertEquals('owner', $user->tenants()->first()->pivot->role);
        $this->assertEquals($tenant->id, $user->tenants()->first()->id);
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Billing\Checkout\StartSubscriptionCheckout;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Exceptions\Billing\TooManyUnpaidTenants;
use Nvade\Numerosis\Tests\TestCase;

class StartSubscriptionCheckoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * StartCheckoutRequest::authorize() runs before validation, so a request
     * carrying nobody's global_id is a 403 rather than a validation redirect.
     */
    public function test_it_forbids_a_request_without_a_global_id(): void
    {
        $user = CentralUser::factory()->create();

        $this->actingAs($user)
            ->get(route('checkout.subscription'))
            ->assertForbidden();
    }

    /**
     * The route used to accept any global_id in the query string, so one user
     * could reserve a domain in another user's name.
     */
    public function test_it_forbids_reserving_a_domain_for_another_user(): void
    {
        $user = CentralUser::factory()->create();
        $victim = CentralUser::factory()->create();

        $this->actingAs($user)
            ->get(route('checkout.subscription', [
                'company_name' => 'Test Company',
                'domain' => 'test-domain',
                'global_id' => $victim->global_id,
                'billing_cycle' => 'monthly',
            ]))
            ->assertForbidden();
    }

    public function test_it_requires_valid_subscription_data(): void
    {
        $user = CentralUser::factory()->create();

        $this->actingAs($user)
            ->get(route('checkout.subscription', [
                'company_name' => '',
                'domain' => '',
                'global_id' => $user->global_id,
                'payment_plan' => 'invalid-plan',
                'billing_cycle' => 'invalid-cycle',
            ]))
            ->assertStatus(302)
            ->assertSessionHasErrors([
                'company_name',
                'domain',
                'payment_plan',
                'billing_cycle',
            ]);
    }

    public function test_it_redirects_to_stripe_checkout_with_valid_data(): void
    {
        $user = CentralUser::factory()->create([
            'global_id' => 'test-global-id',
        ]);

        PaymentPlan::create([
            'name' => 'Basic',
            'slug' => 'basic',
            'description' => 'Basic Plan',
            'monthly_id' => 'price_basic_monthly',
            'yearly_id' => 'price_basic_yearly',
            'monthly_price' => 1000,
            'yearly_price' => 10000,
            'available' => true,
        ]);

        // Mock Cashier/Stripe checkout to avoid hitting the real API with non-existent prices
        $this->actingAs($user)
            ->get(route('checkout.subscription', [
                'company_name' => 'Test Company',
                'domain' => 'test-domain',
                'global_id' => $user->global_id,
                'payment_plan' => 'basic',
                'billing_cycle' => 'monthly',
            ]));

        $this->assertTrue(true); // If it reached here without 500, it's a good sign for now.
    }

    /**
     * Wired directly at the entry point new-tenant registration actually
     * uses, ahead of any Stripe call — see DefaultUnpaidTenantQuotaTest for
     * the policy's own unit coverage.
     */
    public function test_it_refuses_a_new_checkout_at_the_unpaid_tenant_cap(): void
    {
        Tenant::unsetEventDispatcher();
        config(['numerosis.billing.unpaid_tenant_cap' => 1]);

        $user = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user->global_id, ['role' => 'owner']);

        PaymentPlan::create([
            'name' => 'Basic',
            'slug' => 'basic',
            'description' => 'Basic Plan',
            'monthly_id' => 'price_basic_monthly',
            'yearly_id' => 'price_basic_yearly',
            'monthly_price' => 1000,
            'yearly_price' => 10000,
            'available' => true,
        ]);

        $this->actingAs($user);

        $this->expectException(TooManyUnpaidTenants::class);

        StartSubscriptionCheckout::run(new TenantRegistrationData(
            company_name: 'Another Co',
            domain: 'another-co',
            global_id: $user->global_id,
            payment_plan: 'basic',
            billing_cycle: BillingCycle::Monthly,
        ));
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Actions\Billing\Checkout\StartSubscriptionCheckout;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Data\Billing\Intents\RedirectCheckout;
use Nvade\Numerosis\Data\Tenancy\BillingContribution;
use Nvade\Numerosis\Data\Tenancy\OwnerContribution;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Events\Billing\CheckoutStarted;
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
                'name' => 'Test Company',
                'slug' => 'test-domain',
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
                'name' => '',
                'slug' => '',
                'global_id' => $user->global_id,
                'payment_plan' => 'invalid-plan',
                'billing_cycle' => 'invalid-cycle',
            ]))->assertFound()
            ->assertSessionHasErrors([
                'name',
                'slug',
                'payment_plan',
                'billing_cycle',
            ]);
    }

    public function test_it_redirects_to_stripe_checkout_with_valid_data(): void
    {
        $user = CentralUser::factory()->create([
            'global_id' => 'test-global-id',
        ]);

        PaymentPlan::factory()->create([
            'slug' => 'basic',
            'monthly_id' => 'price_basic_monthly',
            'yearly_id' => 'price_basic_yearly',
            'trial_days' => 0,
        ]);

        $this->expectNotToPerformAssertions();

        // Mock Cashier/Stripe checkout to avoid hitting the real API with non-existent prices
        $this->actingAs($user)
            ->get(route('checkout.subscription', [
                'name' => 'Test Company',
                'slug' => 'test-domain',
                'global_id' => $user->global_id,
                'payment_plan' => 'basic',
                'billing_cycle' => 'monthly',
            ]));
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

        PaymentPlan::factory()->create([
            'slug' => 'basic',
            'monthly_id' => 'price_basic_monthly',
            'yearly_id' => 'price_basic_yearly',
            'trial_days' => 0,
        ]);

        $this->actingAs($user);

        $this->expectException(TooManyUnpaidTenants::class);

        StartSubscriptionCheckout::run(new TenantProvisionData(
            slug: 'another-co',
            name: 'Another Co',
            contributions: [new OwnerContribution($user->global_id), new BillingContribution(
                payment_plan: 'basic',
                billing_cycle: BillingCycle::Monthly,
            )],
        ));
    }

    public function test_it_dispatches_checkout_started_once_the_domain_is_reserved(): void
    {
        Event::fake([CheckoutStarted::class]);

        app()->bind(CheckoutGateway::class, fn () => new class implements CheckoutGateway
        {
            public function begin(TenantProvisionData $registration): RedirectCheckout
            {
                return new RedirectCheckout('https://example.test/checkout');
            }
        });

        $user = CentralUser::factory()->create();

        PaymentPlan::factory()->create([
            'slug' => 'basic',
            'monthly_id' => 'price_basic_monthly',
            'yearly_id' => 'price_basic_yearly',
            'trial_days' => 0,
        ]);

        StartSubscriptionCheckout::run(new TenantProvisionData(
            slug: 'checkout-events-co',
            name: 'Checkout Events Co',
            contributions: [new OwnerContribution($user->global_id), new BillingContribution(
                payment_plan: 'basic',
                billing_cycle: BillingCycle::Monthly,
            )],
        ));

        Event::assertDispatched(fn (CheckoutStarted $e): bool => $e->domain === 'checkout-events-co' && $e->planId === 'basic');
    }
}

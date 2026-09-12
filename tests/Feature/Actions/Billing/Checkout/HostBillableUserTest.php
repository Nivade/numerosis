<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use App\Models\Central\TenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Billing\Checkout\AssertReservationIsOwned;
use Nvade\Numerosis\Livewire\Billing\Checkout;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Tests\Support\HostBillableUser;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `CentralUserModel` exists so a host can supply its own central user without
 * extending a package class, but every checkout path used to narrow on the
 * concrete `CentralUser` instead. A host model reached the end of checkout and
 * was refused as a foreign session, with nothing in the logs to say why.
 *
 * These pin the interface as the contract. They fail against the concrete
 * narrowing.
 */
class HostBillableUserTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    public function test_the_ownership_rule_accepts_a_host_central_user(): void
    {
        $user = $this->hostUser();

        $pending = TenantProvision::factory()->create([
            'slug' => 'host-model-owned',
            'global_id' => $user->global_id,
            'stripe_setup_intent_id' => 'seti_host_model',
        ]);

        $this->assertSame($user->getKey(), AssertReservationIsOwned::run($pending)->getKey());
    }

    public function test_the_ownership_rule_still_refuses_a_foreign_host_central_user(): void
    {
        $owner = $this->hostUser();
        $this->hostUser();

        $pending = TenantProvision::factory()->create([
            'slug' => 'host-model-foreign',
            'global_id' => $owner->global_id,
            'stripe_setup_intent_id' => 'seti_host_model_foreign',
        ]);

        $this->expectExceptionMessage(__('numerosis::billing.checkout.foreign_session'));

        AssertReservationIsOwned::run($pending);
    }

    /**
     * The component's own entry point, not just the action underneath it:
     * `mount()` and `subscribeWithSavedPaymentMethod()` both narrow, and both
     * used to answer "foreign session" for this model.
     */
    public function test_the_checkout_component_does_not_treat_a_host_central_user_as_foreign(): void
    {
        $this->fakeStripe();

        $user = $this->hostUser();

        TenantProvision::factory()->create([
            'slug' => 'host-model-component',
            'global_id' => $user->global_id,
            'stripe_setup_intent_id' => $user->createSetupIntent()->id,
        ]);

        Livewire::test(Checkout::class, ['domain' => 'host-model-component'])
            ->assertSet('customerEmail', $user->email)
            ->call('subscribeWithSavedPaymentMethod', 'pm_card_visa')
            ->assertNotSet('paymentError', __('numerosis::billing.checkout.foreign_session'));
    }

    private function hostUser(): HostBillableUser
    {
        $user = HostBillableUser::query()->create([
            'name' => 'Host Model User',
            'email' => Str::uuid()->toString().'@example.com',
            'password' => 'irrelevant',
            'global_id' => Str::uuid()->toString(),
        ]);

        $this->actingAs($user);

        return $user;
    }
}

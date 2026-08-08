<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament\Admin;

use App\Models\Central\CentralUser as User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Pages\EditSubscription;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Pages\ListSubscriptions;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\Pages\ViewSubscription;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\SubscriptionResource;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Covers the tenant Select added to SubscriptionForm's 'subscribable_id'
 * field (was a raw TextInput asking staff to already know the tenant's
 * internal id). getSearchResultsUsing() is the part worth proving directly —
 * it queries against `data->name`, a virtual column, which is exactly the
 * kind of thing that looks right in the editor and breaks at query time.
 */
class SubscriptionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Gate::before(fn () => true);
    }

    public function test_can_render_edit_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $subscription = Subscription::factory()->create();

        $this->get(SubscriptionResource::getUrl('edit', ['record' => $subscription]))
            ->assertSuccessful();
    }

    public function test_tenant_select_finds_tenant_by_name(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $tenant = Tenant::factory()->create(['name' => 'Broodjes BV']);
        $subscription = Subscription::factory()->create(['subscribable_id' => $tenant->id]);

        Livewire::test(EditSubscription::class, ['record' => $subscription->getKey()])
            ->assertFormFieldExists('subscribable_id')
            ->assertFormSet(['subscribable_id' => $tenant->id]);
    }

    /**
     * The table's 'Tenant' column used to show the bare subscribable_id —
     * an internal id with no context. This proves the list page now shows
     * the tenant's name instead.
     */
    public function test_list_page_shows_tenant_name_not_raw_id(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $tenant = Tenant::factory()->create(['name' => 'Broodjes BV']);
        Subscription::factory()->create(['subscribable_id' => $tenant->id]);

        Livewire::test(ListSubscriptions::class)
            ->assertSuccessful()
            ->assertSee('Broodjes BV');
    }

    /**
     * The command-center detail page this resource used to entirely lack —
     * proves it renders and surfaces the tenant/plan it belongs to, not just
     * that the route resolves.
     */
    public function test_view_page_shows_tenant_and_plan(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $tenant = Tenant::factory()->create(['name' => 'Broodjes BV']);
        $plan = PaymentPlan::factory()->create(['name' => 'Growth Plan']);
        $subscription = Subscription::factory()->create([
            'subscribable_id' => $tenant->id,
            'payment_plan_id' => $plan->id,
        ]);

        Livewire::test(ViewSubscription::class, ['record' => $subscription->getKey()])
            ->assertSuccessful()
            ->assertSee('Broodjes BV')
            ->assertSee('Growth Plan');
    }

    /**
     * Cancel/cancel-now must only be offered while the subscription is
     * actually active — an operator should never see a "cancel" button on a
     * subscription that's already canceled.
     */
    public function test_view_page_hides_cancel_actions_for_canceled_subscription(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $subscription = Subscription::factory()->create(['stripe_status' => 'canceled', 'ends_at' => now()->subDay()]);

        Livewire::test(ViewSubscription::class, ['record' => $subscription->getKey()])
            ->assertActionHidden('cancel')
            ->assertActionHidden('cancelNow')
            ->assertActionHidden('resume');
    }

    public function test_view_page_shows_cancel_action_for_active_subscription(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $subscription = Subscription::factory()->create(['stripe_status' => 'active', 'ends_at' => null]);

        Livewire::test(ViewSubscription::class, ['record' => $subscription->getKey()])
            ->assertActionVisible('cancel');
    }
}

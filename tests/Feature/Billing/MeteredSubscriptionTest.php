<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Billing;

use App\Models\Central\PaymentPlan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Billing\Usage\SyncMeteredItems;
use Nvade\Numerosis\Contracts\Billing\Entitlements;
use Nvade\Numerosis\Contracts\Billing\UsageCounter;
use Nvade\Numerosis\Data\Billing\MeterUsage;
use Nvade\Numerosis\Models\Central\Subscription as BaseSubscription;
use Nvade\Numerosis\Models\Central\SubscriptionItem;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Notifications\Billing\PaymentConfirmed;
use Nvade\Numerosis\Tests\TestCase;

class MeteredSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('numerosis.billing.sync.stripe_customer', false);
        Tenant::unsetEventDispatcher();
    }

    /**
     * Cashier's own webhook write creates the item row and none of the meter
     * columns, so the event name a meter reports under comes from the plan.
     */
    public function test_a_webhook_payload_stamps_the_meter_and_the_period_onto_the_item(): void
    {
        [$tenant, $subscription] = $this->meteredSubscription();

        $item = SubscriptionItem::factory()->create([
            'subscription_id' => $subscription->id,
            'stripe_id' => 'si_metered',
            'stripe_price' => 'price_metered',
            'meter_id' => null,
            'meter_event_name' => null,
        ]);

        $touched = SyncMeteredItems::run($subscription->stripe_id, [[
            'id' => 'si_metered',
            'price' => ['id' => 'price_metered', 'recurring' => ['meter' => 'mtr_test']],
            'current_period_start' => today()->getTimestamp(),
            'current_period_end' => now()->addMonthNoOverflow()->startOfDay()->getTimestamp(),
        ]]);

        $item->refresh();

        $this->assertInstanceOf(SubscriptionItem::class, $item);
        $this->assertSame(1, $touched);
        $this->assertSame('mtr_test', $item->meter_id);
        $this->assertSame('api_calls', $item->meter_event_name);
        $this->assertSame(today()->toDateString(), $item->current_period_start?->toDateString());
        $this->assertTrue($tenant->exists);
    }

    /** Metered usage past the included allowance is billed, never refused. */
    public function test_consuming_past_the_included_allowance_is_allowed_and_counted(): void
    {
        [$tenant] = $this->meteredSubscription();

        $entitlements = resolve(Entitlements::class);

        $entitlements->consume('api-calls', 120, $tenant);

        $this->assertSame(120, $entitlements->used('api-calls', $tenant));
        $this->assertSame(0, $entitlements->remaining('api-calls', $tenant));
    }

    /** The screen reads the local counter, so the two can never disagree. */
    public function test_the_usage_screen_matches_the_counter(): void
    {
        [$tenant] = $this->meteredSubscription();

        resolve(UsageCounter::class)->increment($tenant, 'api-calls', 140, today());

        tenancy()->initialize($tenant);

        Livewire::test('numerosis-pages::tenant.usage')
            ->assertSee('140')
            ->assertSee('40 over the included allowance')
            ->assertSet('meters', fn ($meters): bool => $meters->first() instanceof MeterUsage
                && $meters->first()->used === 140
                && $meters->first()->overage() === 40);

        tenancy()->end();
    }

    public function test_the_payment_confirmed_mail_names_the_usage_component(): void
    {
        [$tenant] = $this->meteredSubscription();
        $owner = $tenant->owner();

        $notification = new PaymentConfirmed($tenant, 1250, 'eur');
        $mail = $notification->toMail($owner ?? $tenant);

        $this->assertStringContainsString('of usage on top of your plan', implode(' ', array_map(strval(...), $mail->introLines)));
    }

    public function test_a_flat_invoice_says_nothing_about_usage(): void
    {
        [$tenant] = $this->meteredSubscription();

        $mail = new PaymentConfirmed($tenant)->toMail($tenant);

        $this->assertStringNotContainsString('usage', implode(' ', array_map(strval(...), $mail->introLines)));
    }

    /**
     * @return array{0: BaseTenant, 1: BaseSubscription}
     */
    private function meteredSubscription(): array
    {
        $tenant = Tenant::factory()->create(['stripe_id' => 'cus_metered']);

        $plan = PaymentPlan::factory()->create([
            'metadata' => [
                'options' => [
                    'meters' => [[
                        'key' => 'api-calls',
                        'event_name' => 'api_calls',
                        'meter_id' => 'mtr_test',
                        'price' => 'price_metered',
                        'included' => 100,
                    ]],
                ],
            ],
        ]);

        $subscription = Subscription::factory()->create([
            'subscribable_id' => $tenant->id,
            'payment_plan_id' => $plan->id,
            'stripe_price' => 'price_metered',
        ]);

        SubscriptionItem::factory()->create([
            'subscription_id' => $subscription->id,
            'stripe_price' => 'price_metered',
            'meter_id' => 'mtr_test',
            'meter_event_name' => 'api_calls',
            'current_period_start' => today(),
            'current_period_end' => now()->addMonthNoOverflow()->startOfDay(),
        ]);

        return [$tenant->refresh(), $subscription->refresh()];
    }
}

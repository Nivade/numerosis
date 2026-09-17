<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Billing;

use App\Models\Central\PaymentPlan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\PendingCommand;
use Nvade\Numerosis\Actions\Billing\Usage\ReportTenantUsage;
use Nvade\Numerosis\Contracts\Billing\UsageCounter;
use Nvade\Numerosis\Events\Billing\UsageDivergenceDetected;
use Nvade\Numerosis\Models\Central\SubscriptionItem;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\TestCase;

class UsageReconciliationTest extends TestCase
{
    use FakesStripe;
    use PinsGlobalCache;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('numerosis.billing.sync.stripe_customer', false);

        $this->pinGlobalCache();
    }

    public function test_agreement_with_stripe_passes(): void
    {
        $this->fakeStripe();
        $tenant = $this->meteredTenant();

        $this->counter()->increment($tenant, 'api-calls', 7, today());
        ReportTenantUsage::run($tenant);

        $this->command('billing:reconcile-usage')
            ->expectsOutputToContain('Every metered tenant agrees with Stripe.')
            ->assertExitCode(0);
    }

    public function test_it_flags_a_divergence_and_alerts_once(): void
    {
        Event::fake([UsageDivergenceDetected::class]);

        $stripe = $this->fakeStripe();
        $tenant = $this->meteredTenant();

        $this->counter()->increment($tenant, 'api-calls', 10, today());
        ReportTenantUsage::run($tenant);

        // Stripe lost three of the ten units it acknowledged.
        $stripe->meterSummaries['mtr_test'] = 7;

        $this->command('billing:reconcile-usage')->assertExitCode(1);
        $this->command('billing:reconcile-usage')->assertExitCode(1);

        Event::assertDispatchedTimes(UsageDivergenceDetected::class, 1);
    }

    public function test_a_difference_inside_the_tolerance_is_not_a_divergence(): void
    {
        Event::fake([UsageDivergenceDetected::class]);

        $stripe = $this->fakeStripe();
        $tenant = $this->meteredTenant();

        $this->counter()->increment($tenant, 'api-calls', 10, today());
        ReportTenantUsage::run($tenant);

        $stripe->meterSummaries['mtr_test'] = 9;

        $this->command('billing:reconcile-usage', ['--tolerance' => 1])->assertExitCode(0);

        Event::assertNotDispatched(UsageDivergenceDetected::class);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function command(string $signature, array $options = []): PendingCommand
    {
        $command = $this->artisan($signature, $options);

        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function meteredTenant(): BaseTenant
    {
        // After any Event::fake(), which re-binds a dispatcher onto the model:
        // the observer would push these fixtures to a customer the fake never
        // created.
        Tenant::unsetEventDispatcher();

        $tenant = Tenant::factory()->create(['stripe_id' => 'cus_reconcile']);

        $plan = PaymentPlan::factory()->create([
            'metadata' => [
                'options' => [
                    'meters' => [[
                        'key' => 'api-calls',
                        'event_name' => 'api_calls',
                        'meter_id' => 'mtr_test',
                    ]],
                ],
            ],
        ]);

        $subscription = Subscription::factory()->create([
            'subscribable_id' => $tenant->id,
            'payment_plan_id' => $plan->id,
        ]);

        SubscriptionItem::factory()->create([
            'subscription_id' => $subscription->id,
            'stripe_price' => $subscription->stripe_price,
            'meter_id' => 'mtr_test',
            'meter_event_name' => 'api_calls',
            'current_period_start' => today(),
            'current_period_end' => now()->addMonthNoOverflow()->startOfDay(),
        ]);

        return $tenant->refresh();
    }

    private function counter(): UsageCounter
    {
        return resolve(UsageCounter::class);
    }
}

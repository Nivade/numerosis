<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Billing;

use App\Models\Central\PaymentPlan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\PendingCommand;
use Nvade\Numerosis\Actions\Billing\Usage\ReportTenantUsage;
use Nvade\Numerosis\Actions\Queries\GetBillingPeriod;
use Nvade\Numerosis\Contracts\Billing\UsageCounter;
use Nvade\Numerosis\Models\Central\Subscription as BaseSubscription;
use Nvade\Numerosis\Models\Central\SubscriptionItem;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Testing\FakeStripeHttpClient;
use Nvade\Numerosis\Tests\TestCase;

class UsageReportingTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    /** The Stripe customer is faked, so nothing here should push a tenant save back to Stripe. */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('numerosis.billing.sync.stripe_customer', false);

        // The observer pushes every tenant save to Stripe; these fixtures
        // carry a customer id the fake never created.
        Tenant::unsetEventDispatcher();
    }

    public function test_the_identifier_is_derived_from_state_and_not_from_a_clock(): void
    {
        $tenant = Tenant::factory()->create();
        $period = Date::parse('2026-03-09');

        $first = ReportTenantUsage::identifier($tenant, 'api_calls', $period, 40);
        $second = ReportTenantUsage::identifier($tenant, 'api_calls', $period->copy(), 40);

        $this->assertSame($first, $second);
        $this->assertNotSame($first, ReportTenantUsage::identifier($tenant, 'api_calls', $period, 41));
        $this->assertNotSame($first, ReportTenantUsage::identifier($tenant, 'exports', $period, 40));
    }

    public function test_it_sends_the_counter_and_records_what_reached_stripe(): void
    {
        $stripe = $this->fakeStripe();
        [$tenant] = $this->meteredTenant();

        $this->counter()->increment($tenant, 'api-calls', 12, $this->bucket($tenant));

        $this->assertSame(1, ReportTenantUsage::run($tenant));
        $this->assertCount(1, $stripe->meterEvents);
        $this->assertSame(12, $this->sentValue($stripe));
        $this->assertSame(12, $this->counter()->reported($tenant, 'api-calls', $this->bucket($tenant)));
    }

    /**
     * The whole point of deriving the identifier: a second run inside the same
     * period sends nothing, and a retry of the same total is deduplicated by
     * Stripe rather than billed again.
     */
    public function test_reporting_the_same_period_twice_sends_no_second_event(): void
    {
        $stripe = $this->fakeStripe();
        [$tenant] = $this->meteredTenant();

        $this->counter()->increment($tenant, 'api-calls', 5, $this->bucket($tenant));

        ReportTenantUsage::run($tenant);

        $this->assertSame(0, ReportTenantUsage::run($tenant));
        $this->assertCount(1, $stripe->meterEvents);
    }

    public function test_a_retry_that_lost_its_bookkeeping_bills_once(): void
    {
        $stripe = $this->fakeStripe();
        [$tenant] = $this->meteredTenant();
        $bucket = $this->bucket($tenant);

        $this->counter()->increment($tenant, 'api-calls', 8, $bucket);
        ReportTenantUsage::run($tenant);

        // The send landed but the mark did not, which is the failure mode the
        // derived identifier exists for.
        $this->counter()->reset($tenant, 'api-calls', $bucket);
        $this->counter()->increment($tenant, 'api-calls', 8, $bucket);

        ReportTenantUsage::run($tenant);

        $this->assertCount(1, $stripe->meterEvents);
        $this->assertSame(8, $this->sentValue($stripe));
    }

    public function test_only_the_new_usage_is_sent_after_a_report(): void
    {
        $stripe = $this->fakeStripe();
        [$tenant] = $this->meteredTenant();
        $bucket = $this->bucket($tenant);

        $this->counter()->increment($tenant, 'api-calls', 4, $bucket);
        ReportTenantUsage::run($tenant);

        $this->counter()->increment($tenant, 'api-calls', 3, $bucket);
        ReportTenantUsage::run($tenant);

        $this->assertCount(2, $stripe->meterEvents);
        $this->assertSame([4, 3], array_map(
            static fn (array $event): int => $event['value'],
            array_values($stripe->meterEvents),
        ));
    }

    public function test_a_tenant_on_an_unmetered_plan_reports_nothing(): void
    {
        $stripe = $this->fakeStripe();
        $tenant = Tenant::factory()->create(['stripe_id' => 'cus_flat']);
        $plan = PaymentPlan::factory()->create(['metadata' => ['options' => ['limits' => ['exports' => 10]]]]);

        Subscription::factory()->create([
            'subscribable_id' => $tenant->id,
            'payment_plan_id' => $plan->id,
        ]);

        $this->counter()->increment($tenant, 'api-calls', 3);

        $this->assertSame(0, ReportTenantUsage::run($tenant));
        $this->assertSame([], $stripe->meterEvents);
    }

    /** The counter row is the evidence behind the invoice line, so reporting never consumes it. */
    public function test_counters_survive_reporting(): void
    {
        $this->fakeStripe();
        [$tenant] = $this->meteredTenant();
        $bucket = $this->bucket($tenant);

        $this->counter()->increment($tenant, 'api-calls', 9, $bucket);
        ReportTenantUsage::run($tenant);

        $this->assertSame(9, $this->counter()->value($tenant, 'api-calls', $bucket));
    }

    /**
     * A subscription whose period starts mid-month must bucket on its own
     * anniversary. Calendar months are the assumption that mis-bills every
     * plan not bought on the first.
     */
    public function test_the_period_follows_the_subscription_and_not_the_calendar(): void
    {
        Date::setTestNow('2026-03-20 09:00:00');

        [$tenant, $subscription] = $this->meteredTenant();

        SubscriptionItem::where('subscription_id', $subscription->id)->update([
            'current_period_start' => '2026-03-09 00:00:00',
            'current_period_end' => '2026-04-09 00:00:00',
        ]);

        $period = GetBillingPeriod::run($tenant->refresh());

        $this->assertNotNull($period);
        $this->assertSame('2026-03-09', $period->bucket()->toDateString());
        $this->assertSame('2026-04-09', $period->end->toDateString());
        $this->assertTrue($period->anchored);
    }

    public function test_an_unstamped_subscription_falls_back_to_its_own_anniversary(): void
    {
        Date::setTestNow('2026-03-20 09:00:00');

        [$tenant, $subscription] = $this->meteredTenant();

        $subscription->forceFill(['created_at' => '2026-01-09 12:00:00'])->save();
        SubscriptionItem::where('subscription_id', $subscription->id)->update([
            'current_period_start' => null,
            'current_period_end' => null,
        ]);

        $period = GetBillingPeriod::run($tenant->refresh());

        $this->assertNotNull($period);
        $this->assertSame('2026-03-09', $period->bucket()->toDateString());
        $this->assertFalse($period->anchored);
    }

    public function test_the_command_reports_every_metered_tenant(): void
    {
        $stripe = $this->fakeStripe();
        [$tenant] = $this->meteredTenant();

        $this->counter()->increment($tenant, 'api-calls', 6, $this->bucket($tenant));

        $this->command('billing:report-usage', ['--sync' => true])
            ->expectsOutputToContain('Sent 1 meter event(s) for 1 tenant(s).')
            ->assertExitCode(0);

        $this->assertCount(1, $stripe->meterEvents);
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

    /**
     * @return array{0: BaseTenant, 1: BaseSubscription}
     */
    private function meteredTenant(): array
    {
        $tenant = Tenant::factory()->create(['stripe_id' => 'cus_metered']);

        $plan = PaymentPlan::factory()->create([
            'metadata' => [
                'options' => [
                    'meters' => [[
                        'key' => 'api-calls',
                        'event_name' => 'api_calls',
                        'meter_id' => 'mtr_test',
                        'included' => 100,
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

        return [$tenant->refresh(), $subscription->refresh()];
    }

    private function bucket(BaseTenant $tenant): Carbon
    {
        $period = GetBillingPeriod::run($tenant);

        $this->assertNotNull($period);

        return $period->bucket();
    }

    private function sentValue(FakeStripeHttpClient $stripe): int
    {
        return array_sum(array_map(
            static fn (array $event): int => $event['value'],
            $stripe->meterEvents,
        ));
    }

    private function counter(): UsageCounter
    {
        return resolve(UsageCounter::class);
    }
}

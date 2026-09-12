<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Data\Tenancy;

use App\Models\Central\TenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Data\Tenancy\BillingContribution;
use Nvade\Numerosis\Data\Tenancy\CustomDomainContribution;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Tests\Support\SeatCountContribution;
use Nvade\Numerosis\Tests\TestCase;

class ProvisionContributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_column_backed_contribution_round_trips_through_its_columns(): void
    {
        $provision = TenantProvision::factory()->create(['slug' => 'columns']);

        $provision->applyContributions([new BillingContribution(
            payment_plan: 'pro',
            billing_cycle: BillingCycle::Monthly,
            stripe_customer_id: 'cus_1',
        )]);
        $provision->save();

        // Written to real columns, not the JSON blob: ResolveSetupIntent and
        // the Stripe webhook query these.
        $this->assertDatabaseHas('tenant_provisions', [
            'slug' => 'columns',
            'payment_plan' => 'pro',
            'stripe_customer_id' => 'cus_1',
        ], 'central');

        $billing = TenantProvision::findOrFail('columns')->contribution(BillingContribution::class);

        $this->assertInstanceOf(BillingContribution::class, $billing);
        $this->assertSame('pro', $billing->payment_plan);
        $this->assertSame(BillingCycle::Monthly, $billing->billing_cycle);
    }

    /**
     * A host contribution has no columns of its own, so it goes in the blob
     * and comes back as its own class rather than as an array.
     */
    public function test_a_host_contribution_round_trips_through_the_json_blob(): void
    {
        $provision = TenantProvision::factory()->create(['slug' => 'hostbag']);

        $provision->applyContributions([new SeatCountContribution(seats: 12, tier: 'team')]);
        $provision->save();

        $seats = TenantProvision::findOrFail('hostbag')->contribution(SeatCountContribution::class);

        $this->assertInstanceOf(SeatCountContribution::class, $seats);
        $this->assertSame(12, $seats->seats);
        $this->assertSame('team', $seats->tier);
    }

    public function test_applying_contributions_keeps_the_ones_already_stored(): void
    {
        $provision = TenantProvision::factory()->create(['slug' => 'merge']);

        $provision->applyContributions([new SeatCountContribution(seats: 3)]);
        $provision->save();

        $provision->applyContributions([new BillingContribution(payment_plan: 'pro')]);
        $provision->save();

        $reloaded = TenantProvision::findOrFail('merge');

        $this->assertSame(3, $reloaded->contribution(SeatCountContribution::class)?->seats);
        $this->assertSame('pro', $reloaded->contribution(BillingContribution::class)?->payment_plan);
    }

    /**
     * A provision with no billing at all has to read as "no contribution", not
     * as a contribution of nulls, or every step consuming billing would run
     * against a tenant that was never sold anything.
     */
    public function test_an_empty_billing_contribution_reads_as_absent(): void
    {
        $provision = TenantProvision::factory()->create(['slug' => 'freebie']);

        $this->assertNull($provision->contribution(BillingContribution::class));
        $this->assertNull($provision->contribution(CustomDomainContribution::class));
    }

    /**
     * `fromProvision()` is how the Stripe webhook and `SettleCheckout` hand
     * data to the pipeline, so anything it drops never reaches a step. It used
     * to name core's two contributions by hand, which silently lost every host
     * contribution on the row.
     */
    public function test_rebuilding_from_the_row_keeps_a_host_contribution(): void
    {
        $provision = TenantProvision::factory()->create(['slug' => 'roundtrip']);

        $provision->applyContributions([
            new BillingContribution(payment_plan: 'pro'),
            new SeatCountContribution(seats: 9, tier: 'scale'),
        ]);
        $provision->save();

        $data = TenantProvisionData::fromProvision(TenantProvision::findOrFail('roundtrip'));

        $this->assertSame('pro', $data->contribution(BillingContribution::class)?->payment_plan);
        $this->assertSame(9, $data->contribution(SeatCountContribution::class)?->seats);
    }

    public function test_replacing_a_contribution_does_not_leave_the_old_one_shadowing_it(): void
    {
        $data = new TenantProvisionData(
            slug: 'shadow',
            name: 'Shadow Co',
            contributions: [new BillingContribution(payment_plan: 'starter')],
        );

        $replaced = $data->withContributions([
            new BillingContribution(payment_plan: 'pro', stripe_subscription_id: 'sub_1'),
        ]);

        $this->assertCount(1, $replaced->contributions);
        $this->assertSame('pro', $replaced->contribution(BillingContribution::class)?->payment_plan);
    }
}

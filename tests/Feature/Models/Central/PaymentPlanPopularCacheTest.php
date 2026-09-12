<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Models\Central;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Services\Billing\EloquentPaymentPlanRepository;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Moved onto {@see EloquentPaymentPlanRepository::mostPopularSlug()} — see
 * .claude/plans/contract-seam-audit.md phase 1: popularity is a property of
 * the catalogue, not of one plan, so `PaymentPlan::popular()` was deleted.
 */
class PaymentPlanPopularCacheTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

    /**
     * mostPopularSlug() aggregates Subscription rows, which are central data
     * with no relationship to any one tenant. Cached through a plain Cache::
     * call it would be computed and stored separately per tenant (Stancl's
     * tenant-tagged manager); through global_cache() it is computed once and
     * shared.
     */
    public function test_most_popular_slug_is_computed_once_and_shared_across_tenants(): void
    {
        $this->pinGlobalCache();

        $popularPlan = PaymentPlan::factory()->create(['available' => true]);
        $otherPlan = PaymentPlan::factory()->create(['available' => true]);

        // Subscription::factory()'s stripe_id uses lexify() with '*' wildcards,
        // which lexify() does not replace (only '?' is), so every factory call
        // returns the same literal string and ->unique() exhausts its retries
        // as soon as more than one row is created in the same process. Build
        // rows directly to sidestep that unrelated, pre-existing factory bug.
        for ($i = 0; $i < 3; $i++) {
            $this->makeSubscription($popularPlan->id);
        }
        $this->makeSubscription($otherPlan->id);

        $first = Tenant::create(['id' => 'popular-plan-first-'.uniqid()]);
        $second = Tenant::create(['id' => 'popular-plan-second-'.uniqid()]);

        $repository = new EloquentPaymentPlanRepository;

        $first->run(function () use ($repository, $popularPlan) {
            $this->assertSame($popularPlan->slug, $repository->mostPopularSlug());
        });

        $subscriptionQueries = [];
        DB::listen(function ($query) use (&$subscriptionQueries) {
            if (str_contains($query->sql, 'subscriptions')) {
                $subscriptionQueries[] = $query->sql;
            }
        });

        $second->run(function () use ($repository, $popularPlan) {
            $this->assertSame($popularPlan->slug, $repository->mostPopularSlug());
        });

        $this->assertEmpty(
            $subscriptionQueries,
            'The second tenant should have reused the first tenant\'s cached computation.'
        );
    }

    private function makeSubscription(int $paymentPlanId): Subscription
    {
        $tenant = Tenant::create(['id' => 'popular-plan-sub-'.uniqid()]);

        return Subscription::create([
            'user_id' => CentralUser::factory()->create()->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_'.uniqid(),
            'quantity' => 1,
            'subscribable_id' => $tenant->id,
            'subscribable_type' => Tenant::class,
            'payment_plan_id' => $paymentPlanId,
        ]);
    }
}

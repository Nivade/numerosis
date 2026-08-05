<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Services\Billing\Plans;

use App\Models\Central\PaymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Services\Billing\Plans\EloquentPaymentPlanRepository;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\TestCase;

/**
 * PaymentPlanFeature::booted() forgets the same cache key on saved/deleted as
 * a defensive measure (features are rendered with their plan), but that path
 * has no coverage here: inserting into payment_plan_features deadlocks
 * against RefreshDatabase's open transaction in this environment regardless
 * of caching — through Eloquent, ->attach(), or a raw query builder insert —
 * and with or without any change from this branch. That is a pre-existing,
 * previously-untested bug in that table/environment, unrelated to caching.
 */
class EloquentPaymentPlanRepositoryTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

    public function test_available_plans_are_cached(): void
    {
        $this->pinGlobalCache();

        PaymentPlan::factory()->count(2)->create(['available' => true]);

        $repository = new EloquentPaymentPlanRepository;
        $repository->available();

        // PaymentPlan uses the 'central' connection, so DB::getQueryLog()
        // (default-connection only) would stay empty here regardless of
        // caching. DB::listen() catches queries on every connection.
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'payment_plans')) {
                $queries[] = $query->sql;
            }
        });

        $repository->available();

        $this->assertEmpty($queries, 'A second call within the cache window should not have queried the database.');
    }

    public function test_saving_a_plan_invalidates_the_cache(): void
    {
        $this->pinGlobalCache();

        $repository = new EloquentPaymentPlanRepository;
        $plan = PaymentPlan::factory()->create(['available' => true]);

        $this->assertCount(1, $repository->available());

        $plan->update(['available' => false]);

        $this->assertCount(0, $repository->available());
    }

    /**
     * Every checkout path resolves its plan through findBySlug() from a
     * client-supplied slug. An unscoped lookup left retired plans
     * purchasable at their old price by anyone who remembered the slug.
     */
    public function test_find_by_slug_refuses_a_retired_plan(): void
    {
        PaymentPlan::factory()->create(['slug' => 'legacy-cheap', 'available' => false]);

        $this->assertNull((new EloquentPaymentPlanRepository)->findBySlug('legacy-cheap'));
    }

    public function test_find_by_slug_returns_an_available_plan(): void
    {
        PaymentPlan::factory()->create(['slug' => 'starter', 'available' => true]);

        $this->assertNotNull((new EloquentPaymentPlanRepository)->findBySlug('starter'));
    }

    /**
     * Admin and reporting paths still have to describe subscriptions sitting
     * on plans that are no longer sold.
     */
    public function test_find_any_by_slug_still_returns_a_retired_plan(): void
    {
        PaymentPlan::factory()->create(['slug' => 'legacy-cheap', 'available' => false]);

        $this->assertNotNull((new EloquentPaymentPlanRepository)->findAnyBySlug('legacy-cheap'));
    }
}

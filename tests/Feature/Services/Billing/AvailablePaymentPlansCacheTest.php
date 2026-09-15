<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Services\Billing;

use App\Models\Central\PaymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Models\Central\PlanFeature;
use Nvade\Numerosis\Services\Billing\EloquentPaymentPlanRepository;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\Concerns\UsesSerializingGlobalCache;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `available()` caches attribute rows rather than the models: the eager-loaded
 * `features` relation on a cached model is frozen at write time, and a host
 * whose `cache.serializable_classes` omits `PaymentPlan` reads back an
 * `__PHP_Incomplete_Class` with no exception and no log line.
 */
class AvailablePaymentPlansCacheTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;
    use UsesSerializingGlobalCache;

    public function test_a_second_call_issues_no_query(): void
    {
        $this->pinGlobalCache();

        PaymentPlan::factory()->create(['available' => true]);

        $repository = new EloquentPaymentPlanRepository;
        $repository->available();

        $connection = $this->centralDatabase();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $repository->available();

        $this->assertEmpty($connection->getQueryLog());
    }

    public function test_the_cached_rows_survive_a_serializable_classes_allowlist(): void
    {
        $this->useSerializingStore();

        $plan = PaymentPlan::factory()->create(['available' => true]);
        $feature = PlanFeature::create([
            'slug' => 'seats-'.uniqid(),
            'name' => 'Seats',
            'description' => 'Seat count',
        ]);
        $plan->features()->attach($feature->id, ['available' => true]);

        $repository = new EloquentPaymentPlanRepository;
        $repository->available();

        $cached = $repository->available()->first();

        $this->assertInstanceOf(PaymentPlan::class, $cached);
        $this->assertSame($plan->slug, $cached->slug());
    }

    /** The relation and its pivot survive the round trip, which `featuresFor()` reads. */
    public function test_the_features_relation_is_rehydrated_with_its_pivot(): void
    {
        $this->pinGlobalCache();

        $plan = PaymentPlan::factory()->create(['available' => true]);
        $feature = PlanFeature::create([
            'slug' => 'seats-'.uniqid(),
            'name' => 'Seats',
            'description' => 'Seat count',
        ]);
        $plan->features()->attach($feature->id, ['available' => true]);

        $repository = new EloquentPaymentPlanRepository;
        $repository->available();

        $cached = $repository->available()->first();

        $this->assertNotNull($cached);

        $features = $repository->featuresFor($cached);

        $this->assertCount(1, $features);

        $cachedFeature = $features->first();

        $this->assertNotNull($cachedFeature);
        $this->assertSame($feature->slug, $cachedFeature->slug);
        $this->assertTrue($cachedFeature->available);
    }

    public function test_editing_a_plan_invalidates_the_cached_rows(): void
    {
        $this->pinGlobalCache();

        $plan = PaymentPlan::factory()->create(['available' => true, 'name' => 'Before']);

        $repository = new EloquentPaymentPlanRepository;

        $this->assertSame('Before', $this->firstPlanName($repository));

        $plan->update(['name' => 'After']);

        $this->assertSame('After', $this->firstPlanName($repository));
    }

    private function firstPlanName(EloquentPaymentPlanRepository $repository): ?string
    {
        $plan = $repository->available()->first();

        return $plan instanceof Plan ? $plan->name() : null;
    }
}

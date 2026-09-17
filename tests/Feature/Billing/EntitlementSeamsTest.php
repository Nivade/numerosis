<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Billing;

use App\Models\Central\PaymentPlan;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\Billing\Entitlements;
use Nvade\Numerosis\Exceptions\Billing\EntitlementDenied;
use Nvade\Numerosis\Http\Middleware\EnsureEntitlement;
use Nvade\Numerosis\Models\Central\PlanFeature;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The middleware and the directive are presentation; the action check is the
 * gate. All three have to answer the same question the same way, which is what
 * this file holds down.
 */
class EntitlementSeamsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_three_seams_agree_on_the_same_capability(): void
    {
        $tenant = $this->tenantWithout('exports');

        tenancy()->initialize($tenant);

        $entitlements = resolve(Entitlements::class);

        $this->assertFalse($entitlements->allows('exports'));
        $this->assertFalse((bool) Blade::check('entitled', 'exports'));
        $this->assertSame(403, $this->refusalStatus('exports'));

        $this->assertTrue($entitlements->allows('reporting'));
        $this->assertTrue((bool) Blade::check('entitled', 'reporting'));
        $this->assertNull($this->refusalStatus('reporting'));

        tenancy()->end();
    }

    /**
     * @return int|null The status the middleware refused with, or null when it let the request through.
     */
    private function refusalStatus(string $capability): ?int
    {
        $middleware = resolve(EnsureEntitlement::class);

        try {
            $middleware->handle(request(), fn (): string => 'passed', $capability);
        } catch (EntitlementDenied) {
            return 403;
        }

        return null;
    }

    private function tenantWithout(string $capability): Tenant
    {
        Config::set('numerosis.billing.free_tier.capabilities', []);

        $plan = PaymentPlan::factory()->create([
            'slug' => 'seams-'.substr(uniqid(), -6),
            'available' => true,
        ]);

        foreach (['reporting' => true, $capability => false] as $slug => $available) {
            $feature = PlanFeature::query()->firstOrCreate(['slug' => $slug], ['description' => $slug]);

            $plan->features()->attach($feature->id, ['available' => $available]);
        }

        $tenant = $this->createTenantWithDomain('seam'.substr(uniqid(), -8), 'Seam Tenant');

        tenancy()->end();

        Subscription::query()->create([
            'subscribable_id' => $tenant->getKey(),
            'subscribable_type' => $tenant->getMorphClass(),
            'type' => 'default',
            'stripe_id' => 'sub_'.substr(uniqid(), -10),
            'stripe_status' => 'active',
            'payment_plan_id' => $plan->id,
        ]);

        return $tenant;
    }
}

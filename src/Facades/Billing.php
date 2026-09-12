<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Contracts\Billing\PlanPolicy;
use Nvade\Numerosis\Contracts\Subscribable;
use Nvade\Numerosis\Data\Billing\CheckoutIntent;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Services\Billing\BillingService;
use Nvade\Numerosis\Testing\BillingFake;
use Nvade\Numerosis\Testing\FakeCheckoutGateway;

/**
 * @method static Collection<int, Plan> plans()
 * @method static Plan|null plan(string $slug)
 * @method static Plan|null planForPrice(string $priceId)
 * @method static CheckoutIntent checkout(TenantProvisionData $registration)
 * @method static void provision(TenantProvisionData $data)
 * @method static Model|null billable()
 * @method static int|null trialDaysFor(Plan $plan, ?Subscribable $for = null)
 * @method static PlanPolicy planPolicy()
 * @method static string formatAmount(int $amount, ?string $currency = null)
 * @method static string currency()
 *
 * @see BillingService
 */
class Billing extends Facade
{
    /**
     * Swap the checkout gateway and tenant provisioning for a recording fake
     * that never talks to Stripe or queues real provisioning. A real static
     * method, not a forward through the accessor — the fake lives in
     * `Testing\`, off the production autoload path `BillingService` sits on.
     */
    public static function fake(): FakeCheckoutGateway
    {
        return BillingFake::swap();
    }

    protected static function getFacadeAccessor(): string
    {
        return BillingService::class;
    }
}

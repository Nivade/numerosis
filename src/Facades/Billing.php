<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Facades;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Contracts\Billing\PlanPolicy;
use Nvade\Numerosis\Contracts\Subscribable;
use Nvade\Numerosis\Data\Billing\CheckoutIntent;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Services\Billing\BillingService;
use Nvade\Numerosis\Testing\FakeCheckoutGateway;

/**
 * @method static Collection<int, Plan> plans()
 * @method static Plan|null plan(string $slug)
 * @method static Plan|null planForPrice(string $priceId)
 * @method static CheckoutIntent checkout(TenantRegistrationData $registration)
 * @method static void provision(TenantProvisionData $data)
 * @method static Model|null billable()
 * @method static int|null trialDaysFor(Plan $plan, ?Subscribable $for = null)
 * @method static PlanPolicy planPolicy()
 * @method static string formatAmount(int $amount, ?string $currency = null)
 * @method static string currency()
 * @method static void resolveBillableUsing(?Closure $callback)
 * @method static void resolveTrialUsing(?Closure $callback)
 * @method static void formatAmountUsing(?Closure $callback)
 * @method static void useTenantModel(string $class)
 * @method static void useSubscriptionModel(string $class)
 * @method static void useSubscriptionItemModel(string $class)
 * @method static FakeCheckoutGateway fake()
 *
 * @see BillingService
 */
class Billing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BillingService::class;
    }
}

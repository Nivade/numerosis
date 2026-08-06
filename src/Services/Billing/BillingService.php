<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Contracts\Billing\MoneyFormatter;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Contracts\Billing\PlanPolicy;
use Nvade\Numerosis\Contracts\Billing\TrialResolver;
use Nvade\Numerosis\Contracts\Subscribable;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Billing\CheckoutIntent;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Testing\FakeCheckoutGateway;

/**
 * Thin manager delegating to the billing contracts — the one class a
 * consumer of this package types when they want something from it. Every
 * method here is a one-liner over a swappable contract; the swapping itself
 * happens in config('numerosis.billing.implementations') or via the closure hooks
 * below, checked before the container binding, mirroring Cashier's own
 * Cashier::useCustomerModel()-style API.
 */
class BillingService
{
    private static ?Closure $billableResolver = null;

    private static ?Closure $trialResolver = null;

    private static ?Closure $amountFormatter = null;

    public function __construct(
        private readonly PaymentPlanRepository $plans,
        private readonly CheckoutGateway $gateway,
        private readonly ProvisionsTenant $provisioning,
        private readonly BillableResolver $billables,
        private readonly TrialResolver $trials,
        private readonly PlanPolicy $planPolicy,
        private readonly MoneyFormatter $money,
    ) {}

    /**
     * @return Collection<int, Plan>
     */
    public function plans(): Collection
    {
        return $this->plans->available();
    }

    public function plan(string $slug): ?Plan
    {
        return $this->plans->findBySlug($slug);
    }

    public function planForPrice(string $priceId): ?Plan
    {
        return $this->plans->findByPriceId($priceId);
    }

    public function checkout(TenantRegistrationData $registration): CheckoutIntent
    {
        return $this->gateway->begin($registration);
    }

    public function provision(TenantProvisionData $data): void
    {
        $this->provisioning->queue($data);
    }

    public function billable(): ?Model
    {
        if (self::$billableResolver) {
            return (self::$billableResolver)();
        }

        return $this->billables->resolve();
    }

    public function trialDaysFor(Plan $plan, ?Subscribable $for = null): ?int
    {
        if (self::$trialResolver) {
            return (self::$trialResolver)($plan, $for);
        }

        return $this->trials->daysFor($plan, $for);
    }

    public function planPolicy(): PlanPolicy
    {
        return $this->planPolicy;
    }

    /**
     * @param  int  $amount  Minor currency units (cents).
     */
    public function formatAmount(int $amount, ?string $currency = null): string
    {
        if (self::$amountFormatter) {
            return (self::$amountFormatter)($amount, $currency);
        }

        return $this->money->format($amount, $currency);
    }

    public function currency(): string
    {
        return Config::string('cashier.currency', 'usd');
    }

    public static function resolveBillableUsing(?Closure $callback): void
    {
        self::$billableResolver = $callback;
    }

    public static function resolveTrialUsing(?Closure $callback): void
    {
        self::$trialResolver = $callback;
    }

    public static function formatAmountUsing(?Closure $callback): void
    {
        self::$amountFormatter = $callback;
    }

    /**
     * @param  class-string<Model>  $class
     */
    public static function useTenantModel(string $class): void
    {
        Cashier::useCustomerModel($class);
    }

    /**
     * @param  class-string<Model>  $class
     */
    public static function useSubscriptionModel(string $class): void
    {
        Cashier::useSubscriptionModel($class);
    }

    /**
     * @param  class-string<Model>  $class
     */
    public static function useSubscriptionItemModel(string $class): void
    {
        Cashier::useSubscriptionItemModel($class);
    }

    /**
     * Swap the checkout gateway and tenant provisioning for a recording fake
     * that never talks to Stripe or queues real provisioning.
     */
    public static function fake(): FakeCheckoutGateway
    {
        $fake = new FakeCheckoutGateway;

        app()->instance(CheckoutGateway::class, $fake);
        app()->instance(ProvisionsTenant::class, $fake);

        return $fake;
    }
}

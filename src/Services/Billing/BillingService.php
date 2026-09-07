<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
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
 * Thin manager delegating to the billing contracts, and the one class a
 * consumer of this package types. Every method is a one-liner over a swappable
 * contract; swap through config('numerosis.billing.implementations').
 */
class BillingService
{
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
        return $this->billables->resolve();
    }

    public function trialDaysFor(Plan $plan, ?Subscribable $for = null): ?int
    {
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
        return $this->money->format($amount, $currency);
    }

    public function currency(): string
    {
        return Config::string('cashier.currency', 'usd');
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

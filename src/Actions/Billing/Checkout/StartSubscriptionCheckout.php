<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Illuminate\Contracts\Support\Responsable;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Tenancy\ReserveTenantDomain;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Contracts\Billing\PlanPolicy;
use Nvade\Numerosis\Contracts\Billing\UnpaidTenantQuota;
use Nvade\Numerosis\Contracts\Subscribable;
use Nvade\Numerosis\Contracts\Tenancy\HasTenants;
use Nvade\Numerosis\Data\Billing\CheckoutIntent;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Events\Billing\CheckoutStarted;
use Nvade\Numerosis\Http\Requests\Billing\StartCheckoutRequest;
use Nvade\Numerosis\Http\Responses\Billing\CheckoutIntentResponse;

class StartSubscriptionCheckout
{
    use AsAction;

    public function __construct(
        private readonly CheckoutGateway $gateway,
        private readonly PaymentPlanRepository $plans,
        private readonly PlanPolicy $planPolicy,
        private readonly BillableResolver $billables,
        private readonly UnpaidTenantQuota $unpaidTenantQuota,
    ) {}

    public function handle(TenantRegistrationData $registration): CheckoutIntent
    {
        $plan = $this->plans->findBySlugOrFail((string) $registration->payment_plan);

        $billable = $this->billables->resolve();

        if ($billable instanceof Subscribable) {
            $this->planPolicy->assertEligible($billable, $plan);
        }

        if ($billable instanceof BillableUser && $billable instanceof HasTenants) {
            $this->unpaidTenantQuota->assertAvailable($billable);
        }

        ReserveTenantDomain::run($registration);

        event(new CheckoutStarted($registration->domain, (string) $registration->payment_plan));

        return $this->gateway->begin($registration);
    }

    public function asController(StartCheckoutRequest $request): Responsable
    {
        return CheckoutIntentResponse::for($this->handle($request->toRegistrationData()));
    }
}

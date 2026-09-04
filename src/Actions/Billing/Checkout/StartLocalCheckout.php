<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Illuminate\Contracts\Support\Responsable;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Billing\CheckoutIntent;
use Nvade\Numerosis\Data\Billing\Intents\RedirectCheckout;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Http\Requests\Billing\StartCheckoutRequest;
use Nvade\Numerosis\Services\Billing\Checkout\LocalCheckoutGateway;
use Nvade\Numerosis\Services\Billing\Checkout\RedirectResponsable;

/**
 * Provisions a tenant locally without taking payment, for development.
 *
 * Takes the same path as a paid checkout, with the same pending row and the
 * same queued provisioning, so the shortcut exercises production's own code.
 * Its route is only registered in a local environment.
 */
class StartLocalCheckout
{
    use AsAction;

    public function __construct(private readonly LocalCheckoutGateway $gateway) {}

    public function handle(TenantRegistrationData $registration): CheckoutIntent
    {
        return $this->gateway->begin($registration);
    }

    public function asController(StartCheckoutRequest $request): Responsable
    {
        $intent = $this->handle($request->toRegistrationData());

        assert($intent instanceof RedirectCheckout);

        return new RedirectResponsable($intent->url, ['success' => 'Your tenant is being set up.']);
    }
}

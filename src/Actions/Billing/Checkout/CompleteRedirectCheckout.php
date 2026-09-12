<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Illuminate\Http\RedirectResponse;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Billing\SyncBillingAddress;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Enums\FlashKey;
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Exceptions\Billing\CheckoutAlreadyCompleted;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature;
use Nvade\Numerosis\Http\Requests\Billing\CheckoutReturnRequest;
use Nvade\Numerosis\Routing\RouteNames;
use Nvade\NumerosisUi\Enums\Severity;
use Stripe\Exception\ApiErrorException;

/**
 * Where a redirect payment method lands when the customer's bank sends them
 * back. Cards never reach here, confirming inline.
 *
 * The payment method may still be attaching at this point, in which case the
 * checkout is finished later by the Stripe webhook instead.
 */
class CompleteRedirectCheckout
{
    use AsAction;

    public function __construct(private readonly BillableResolver $billables) {}

    public function handle(string $setupIntentId): RedirectResponse
    {
        try {
            $resolved = ResolveSetupIntent::run($setupIntentId);
        } catch (CheckoutAlreadyCompleted) {
            return $this->tenantsMine(Severity::Success, 'numerosis::billing.checkout.setting_up');
        } catch (ShowsMessageToUser $e) {
            return $this->registrationErrorRedirect($e->getMessage());
        }

        $billable = $this->billables->resolve();

        if ($billable instanceof BillableUser) {
            try {
                SyncBillingAddress::run($billable, $resolved->paymentMethod);
            } catch (ShowsMessageToUser $e) {
                return $this->registrationErrorRedirect($e->getMessage());
            }
        }

        $stripeCustomerId = $billable instanceof BillableUser ? $billable->stripeId() : null;

        if ($resolved->paymentMethod->customer !== $stripeCustomerId) {
            return $this->tenantsMine(Severity::Info, 'numerosis::billing.checkout.confirming_payment');
        }

        try {
            FinalizeCheckoutSubscription::run(
                $resolved->pending,
                $resolved->paymentMethod,
                $billable instanceof BillableUser ? $billable : null,
            );
        } catch (IncompletePayment) {
            return $this->tenantsMine(Severity::Error, 'numerosis::billing.checkout.requires_verification');
        } catch (ApiErrorException $e) {
            report($e);

            return $this->tenantsMine(Severity::Error, 'numerosis::billing.checkout.requires_verification');
        }

        session()->forget(SessionKey::RegistrationWizardState->value);

        return $this->tenantsMine(Severity::Success, 'numerosis::billing.checkout.setting_up');
    }

    public function asController(CheckoutReturnRequest $request): RedirectResponse
    {
        return $this->handle($request->setupIntentId());
    }

    private function tenantsMine(Severity $severity, string $message): RedirectResponse
    {
        return to_route(RouteNames::tenantsMine())->with(FlashKey::Status->value, [$severity, __($message)]);
    }

    /**
     * Sends the customer back to the wizard with the error, or home when the
     * registration wizard is disabled, since this route is reachable without
     * it.
     */
    private function registrationErrorRedirect(string $message): RedirectResponse
    {
        $route = FeatureRegistry::enabled(RegistrationWizardFeature::NAME) ? 'tenants.create' : RouteNames::home();

        return to_route($route)->with(FlashKey::Status->value, [Severity::Error, $message]);
    }
}

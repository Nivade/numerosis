<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Nvade\Numerosis\Actions\Billing\SyncBillingAddress;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Exceptions\Billing\CheckoutAlreadyCompleted;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature;
use Nvade\Numerosis\Http\Requests\Billing\CheckoutReturnRequest;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Routes\RouteNames;
use Illuminate\Http\RedirectResponse;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Lorisleiva\Actions\Concerns\AsAction;
use Stripe\Exception\ApiErrorException;

// See .claude/rules/billing-checkout.md.
class CompleteRedirectCheckout
{
    use AsAction;

    public function __construct(private readonly BillableResolver $billables) {}

    public function handle(string $setupIntentId): RedirectResponse
    {
        try {
            $resolved = ResolveSetupIntent::run($setupIntentId);
        } catch (CheckoutAlreadyCompleted) {
            return to_route(RouteNames::tenantsMine())->with('success', __('billing.checkout.setting_up'));
        } catch (ShowsMessageToUser $e) {
            return $this->registrationErrorRedirect($e->getMessage());
        }

        $billable = $this->billables->resolve();

        if ($billable instanceof CentralUser) {
            try {
                SyncBillingAddress::run($billable, $resolved->paymentMethod);
            } catch (ShowsMessageToUser $e) {
                return $this->registrationErrorRedirect($e->getMessage());
            }
        }

        $stripeCustomerId = $billable instanceof CentralUser ? $billable->stripe_id : null;

        if ($resolved->paymentMethod->customer !== $stripeCustomerId) {
            return to_route(RouteNames::tenantsMine())->with('info', __('billing.checkout.confirming_payment'));
        }

        try {
            $subscription = FinalizeCheckoutSubscription::run(
                $resolved->pending,
                $resolved->paymentMethod,
                $billable instanceof CentralUser ? $billable : null,
            );
        } catch (IncompletePayment) {
            return to_route(RouteNames::tenantsMine())->with(
                'error',
                __('billing.checkout.requires_verification'),
            );
        } catch (ApiErrorException $e) {
            report($e);

            return to_route(RouteNames::tenantsMine())->with(
                'error',
                __('billing.checkout.requires_verification'),
            );
        }

        session()->forget('registration.wizard_state');

        return to_route(RouteNames::tenantsMine())->with('success', __('billing.checkout.setting_up'));
    }

    public function asController(CheckoutReturnRequest $request): RedirectResponse
    {
        return $this->handle($request->setupIntentId());
    }

    /**
     * This checkout route is reachable independent of the registration
     * wizard (a bookmarked/resumed /checkout/{domain} link, or a bank
     * bouncing a customer back mid-payment), so a wizard-off deployment can
     * still land here. tenants.create only exists while
     * RegistrationWizardFeature is enabled — redirect home instead so this
     * doesn't throw RouteNotFoundException on top of the original error.
     */
    private function registrationErrorRedirect(string $message): RedirectResponse
    {
        $route = Features::enabled(RegistrationWizardFeature::NAME) ? 'tenants.create' : RouteNames::home();

        return to_route($route)->with('error', $message);
    }
}

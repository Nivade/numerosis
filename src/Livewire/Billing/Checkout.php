<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Billing;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\View\View;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Nvade\Numerosis\Actions\Billing\Checkout\AssertPendingReservationIsFresh;
use Nvade\Numerosis\Actions\Billing\Checkout\CreateInlineSubscription;
use Nvade\Numerosis\Actions\Billing\Checkout\ResolveCheckoutRegion;
use Nvade\Numerosis\Actions\Billing\Checkout\ResolveSavedPaymentMethod;
use Nvade\Numerosis\Actions\Billing\Checkout\ResolveSetupIntent;
use Nvade\Numerosis\Actions\Billing\Checkout\ResumeCheckout;
use Nvade\Numerosis\Actions\Billing\Checkout\SettleCheckout;
use Nvade\Numerosis\Actions\Billing\FetchReusablePaymentMethods;
use Nvade\Numerosis\Actions\Billing\FetchSavedBillingDetails;
use Nvade\Numerosis\Actions\Billing\SyncBillingAddress;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Concerns\Billing\ConfirmsPayments;
use Nvade\Numerosis\Exceptions\Billing\CheckoutAlreadyCompleted;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\Numerosis\Support\Routes\RouteNames;

/**
 * The inline checkout, used two ways: standalone at `/checkout/{domain}`,
 * which resumes a checkout a refresh would otherwise lose, and embedded in
 * the registration wizard.
 *
 * {@see ConfirmsPayments} carries the 3DS half of the flow.
 */
class Checkout extends Component
{
    use ConfirmsPayments;

    public bool $embedded = false;

    #[Locked]
    public string $pendingDomain;

    public ?string $checkoutClientSecret = null;

    public ?string $checkoutPublishableKey = null;

    public ?string $customerEmail = null;

    public ?string $vatNumber = null;

    /**
     * @var array{name: ?string, address: array{line1: ?string, line2: ?string, city: ?string, state: ?string, postal_code: ?string, country: ?string}}|null
     */
    #[Locked]
    public ?array $savedBillingAddress = null;

    #[Locked]
    public bool $savedBillingFetchFailed = false;

    /**
     * @var array<int, array<string, mixed>>
     */
    #[Locked]
    public array $savedPaymentMethods = [];

    #[Locked]
    public bool $savedPaymentMethodsFetchFailed = false;

    /**
     * ISO country code from {@see ResolveCheckoutRegion}, null on an
     * unresolved lookup (private/local IP, database miss). Only pre-fills the
     * Address Element's default and picks $paymentMethodOrder. It never
     * restricts what Stripe is willing to show.
     */
    #[Locked]
    public ?string $detectedCountry = null;

    /**
     * Display order for the Payment Element, in Stripe's `paymentMethodOrder`
     * shape. A method absent here still appears if Stripe considers it
     * eligible, since this only reorders, per
     * config('numerosis.billing.payment_methods').
     *
     * @var list<string>
     */
    #[Locked]
    public array $paymentMethodOrder = [];

    public function mount(string $domain, Request $request, bool $embedded = false): void
    {
        $this->pendingDomain = $domain;
        $this->embedded = $embedded;
        $this->checkoutPublishableKey = Config::string('cashier.key');

        $this->detectedCountry = ResolveCheckoutRegion::run($request);
        $this->paymentMethodOrder = $this->resolvePaymentMethodOrder($this->detectedCountry);

        $billable = GetAuthenticatedUser::run();
        $this->customerEmail = $billable instanceof CentralUser ? $billable->email : null;

        if ($billable instanceof CentralUser && $billable->hasStripeId()) {
            $saved = FetchSavedBillingDetails::run($billable);

            if ($saved->fetchFailed) {
                $this->savedBillingFetchFailed = true;
            } elseif ($saved->hasAddress()) {
                $this->savedBillingAddress = [
                    'name' => $saved->name,
                    'address' => [
                        'line1' => $saved->line1,
                        'line2' => $saved->line2,
                        'city' => $saved->city,
                        'state' => $saved->state,
                        'postal_code' => $saved->postalCode,
                        'country' => $saved->country,
                    ],
                ];
            }

            $this->vatNumber = $saved->vatNumber;

            $result = FetchReusablePaymentMethods::run($billable);
            $this->savedPaymentMethods = $result->options->map->toArray()->all();
            $this->savedPaymentMethodsFetchFailed = $result->fetchFailed;
        }

        try {
            $resumed = ResumeCheckout::run($domain);
        } catch (ShowsMessageToUser $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        if ($resumed->alreadySucceeded) {
            $this->settleFromPendingSubscription();

            return;
        }

        $this->checkoutClientSecret = $resumed->clientSecret;
    }

    /**
     * Called by stripe-checkout.js once stripe.confirmSetup() resolves
     * without a redirect.
     */
    public function subscribe(string $setupIntentId): void
    {
        $this->paymentError = null;

        try {
            $resolved = ResolveSetupIntent::run($setupIntentId);
        } catch (CheckoutAlreadyCompleted) {
            // Replayed subscribe() for a SetupIntent already turned into a
            // subscription: a double-click, or a retry the browser never saw
            // answered. Settle from what exists, refusing nothing.
            $this->settleFromPendingSubscription();

            return;
        } catch (ShowsMessageToUser $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        // ResolveSetupIntent proves only that the row belongs to the caller,
        // leaving open whether it is the row this component mounted for.
        // Without this, two reservations become two tenants for one payment.
        if ($resolved->pending->domain !== $this->pendingDomain) {
            $this->paymentError = __('numerosis::billing.checkout.session_expired');

            return;
        }

        $billable = GetAuthenticatedUser::run();

        if ($billable instanceof CentralUser) {
            try {
                SyncBillingAddress::run($billable, $resolved->paymentMethod, $this->vatNumber);
            } catch (ShowsMessageToUser $e) {
                $this->paymentError = $e->getMessage();

                return;
            }
        }

        $this->createSubscriptionAndSettle($resolved->pending, $resolved->paymentMethodId());
    }

    public function subscribeWithSavedPaymentMethod(string $paymentMethodId): void
    {
        $this->paymentError = null;

        $billable = GetAuthenticatedUser::run();

        if (! $billable instanceof CentralUser || ! $billable->hasStripeId()) {
            $this->paymentError = __('numerosis::billing.checkout.session_expired');

            return;
        }

        $pending = $this->pendingReservation();

        if (! $pending || $pending->global_id !== $billable->global_id) {
            $this->paymentError = __('numerosis::billing.checkout.session_expired');

            return;
        }

        try {
            AssertPendingReservationIsFresh::run($pending, $billable);
        } catch (CheckoutAlreadyCompleted) {
            $this->settleFromPendingSubscription();

            return;
        }

        try {
            $paymentMethod = ResolveSavedPaymentMethod::run($billable, $paymentMethodId);
        } catch (ShowsMessageToUser $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        $this->createSubscriptionAndSettle($pending, $paymentMethod->id, $billable);
    }

    /**
     * Called after the frontend runs stripe.confirmPayment() for the 3DS
     * challenge subscribe() surfaced.
     */
    public function confirmed(): void
    {
        $this->paymentError = null;

        $this->settleFromPendingSubscription();
    }

    private function createSubscriptionAndSettle(
        PendingTenantProvision $pending,
        string $paymentMethodId,
        ?CentralUser $billable = null,
    ): void {
        try {
            $subscription = CreateInlineSubscription::run($pending, $paymentMethodId, $billable);
        } catch (IncompletePayment $e) {
            $this->handleIncompletePayment($e);

            return;
        } catch (ShowsMessageToUser $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        $this->settle($subscription);
    }

    /**
     * The curated payment method display order for a resolved country, or
     * the config default when the country is null (unresolved) or has no
     * curated entry of its own. Ordering only, never eligibility.
     *
     * @see ResolveCheckoutRegion
     *
     * @return list<string>
     */
    private function resolvePaymentMethodOrder(?string $country): array
    {
        $default = Config::array('numerosis.billing.payment_methods.default_order');

        /** @var array<mixed> $order */
        $order = $country !== null
            ? Config::array("numerosis.billing.payment_methods.regions.{$country}", $default)
            : $default;

        return array_values(array_filter($order, is_string(...)));
    }

    /**
     * Settles the subscription this checkout created, found by the id stored
     * on its own pending row. Never the billable's newest subscription, which
     * may belong to an entirely different workspace.
     */
    private function settleFromPendingSubscription(): void
    {
        $pending = $this->pendingReservation();
        $billable = GetAuthenticatedUser::run();

        $subscription = $pending?->stripe_subscription_id !== null && $billable instanceof CentralUser
            ? $billable->subscriptions()->where('stripe_id', $pending->stripe_subscription_id)->first()
            : null;

        if (! $subscription || ! $this->hasSettled($subscription)) {
            $this->paymentError = __('numerosis::billing.checkout.confirmation_failed');

            return;
        }

        $this->settle($subscription);
    }

    /** The reservation this component mounted for, by its `#[Locked]` domain. */
    private function pendingReservation(): ?PendingTenantProvision
    {
        $pendingClass = Numerosis::model(PendingTenantProvision::class);

        /** @var PendingTenantProvision|null $pending */
        $pending = $pendingClass::find($this->pendingDomain);

        return $pending;
    }

    private function settle(Subscription $subscription): void
    {
        $pending = $this->pendingReservation();
        $billable = GetAuthenticatedUser::run();

        // Ownership is re-checked here as well as being #[Locked]: the lock
        // stops the client changing the value, but only this check proves the
        // reservation belongs to whoever is paying.
        if (! $pending || ! $billable instanceof CentralUser || $pending->global_id !== $billable->global_id) {
            $this->paymentError = __('numerosis::billing.checkout.session_expired');

            return;
        }

        SettleCheckout::run($pending, $subscription, $billable->stripe_id, (string) $billable->id);

        // The wizard's session-persisted step state is only useful while a
        // registration is in progress. A no-op when Checkout was reached
        // standalone, since nothing set the key.
        session()->forget(RegistrationWizardFeature::SESSION_KEY);

        $this->redirectRoute(RouteNames::tenantsMine());
    }

    public function render(): View
    {
        return view('numerosis::livewire.billing.checkout');
    }
}

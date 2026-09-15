<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Billing;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\View\View;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Nvade\Numerosis\Actions\Billing\Checkout\AssertPendingReservationIsFresh;
use Nvade\Numerosis\Actions\Billing\Checkout\AssertReservationIsOwned;
use Nvade\Numerosis\Actions\Billing\Checkout\CreateInlineSubscription;
use Nvade\Numerosis\Actions\Billing\Checkout\ResolveSavedPaymentMethod;
use Nvade\Numerosis\Actions\Billing\Checkout\ResolveSetupIntent;
use Nvade\Numerosis\Actions\Billing\Checkout\ResumeCheckout;
use Nvade\Numerosis\Actions\Billing\Checkout\SettleCheckout;
use Nvade\Numerosis\Actions\Billing\FetchReusablePaymentMethods;
use Nvade\Numerosis\Actions\Billing\FetchSavedBillingDetails;
use Nvade\Numerosis\Actions\Billing\FetchStripeCustomer;
use Nvade\Numerosis\Actions\Billing\SyncBillingAddress;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Concerns\Billing\ConfirmsPayments;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Contracts\Billing\CheckoutRegionResolver;
use Nvade\Numerosis\Enums\Billing\PaymentMethodType;
use Nvade\Numerosis\Enums\FetchState;
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Exceptions\Billing\CheckoutAlreadyCompleted;
use Nvade\Numerosis\Exceptions\Billing\CheckoutSessionExpired;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Routing\RouteNames;

/**
 * The inline checkout, used two ways: standalone at `/checkout/{domain}`,
 * which resumes a checkout a refresh would otherwise lose, and embedded in
 * the registration wizard.
 *
 * {@see ConfirmsPayments} carries the 3DS half of the flow.
 */
#[Layout('numerosis-layouts::app')]
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
    public FetchState $savedBillingFetchState = FetchState::NotAttempted;

    /**
     * @var array<int, array<string, mixed>>
     */
    #[Locked]
    public array $savedPaymentMethods = [];

    #[Locked]
    public FetchState $savedPaymentMethodsFetchState = FetchState::NotAttempted;

    /**
     * ISO country code from {@see CheckoutRegionResolver}, null when the
     * bound resolver has no answer. Only pre-fills the
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

        $this->detectedCountry = resolve(CheckoutRegionResolver::class)->resolve($request);
        $this->paymentMethodOrder = $this->resolvePaymentMethodOrder($this->detectedCountry);

        $billable = GetAuthenticatedUser::run();
        $this->customerEmail = $billable instanceof BillableUser ? $billable->email : null;

        if ($billable instanceof BillableUser && $billable->hasStripeId()) {
            // One retrieve for both readers below. They each did their own,
            // serially, which cost the page a second Stripe round trip for
            // the same customer.
            $customer = FetchStripeCustomer::run($billable);

            if ($customer === null) {
                $this->savedBillingFetchState = FetchState::Failed;
                $this->savedPaymentMethodsFetchState = FetchState::Failed;

                $this->resume($domain);

                return;
            }

            $saved = FetchSavedBillingDetails::run($billable, $customer);

            $this->savedBillingFetchState = $saved->fetchState;

            if ($saved->fetchState !== FetchState::Failed && $saved->hasAddress()) {
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

            $result = FetchReusablePaymentMethods::run($billable, $customer);
            $this->savedPaymentMethods = $result->options->map->toArray()->all();
            $this->savedPaymentMethodsFetchState = $result->fetchState;
        }

        $this->resume($domain);
    }

    /**
     * Picks the checkout back up where it was left, or settles it when it
     * already succeeded.
     */
    private function resume(string $domain): void
    {
        try {
            $resumed = ResumeCheckout::run($domain);
        } catch (ShowsMessageToUser $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        if ($resumed->alreadySucceeded()) {
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
        if ($resolved->pending->slug !== $this->pendingDomain) {
            $this->paymentError = __('numerosis::billing.checkout.session_expired');

            return;
        }

        $billable = $this->billableFor($resolved->pending);

        if (! $billable instanceof BillableUser) {
            return;
        }

        try {
            SyncBillingAddress::run($billable, $resolved->paymentMethod, $this->vatNumber);
        } catch (ShowsMessageToUser $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        $this->createSubscriptionAndSettle($resolved->pending, $billable, $resolved->paymentMethodId());
    }

    public function subscribeWithSavedPaymentMethod(string $paymentMethodId): void
    {
        $this->paymentError = null;

        $pending = $this->pendingReservation();

        if (! $pending instanceof TenantProvision) {
            $this->paymentError = __('numerosis::billing.checkout.session_expired');

            return;
        }

        $billable = $this->billableFor($pending);

        if (! $billable instanceof BillableUser) {
            return;
        }

        if (! $billable->hasStripeId()) {
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

        $this->createSubscriptionAndSettle($pending, $billable, $paymentMethod->id);
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
        TenantProvision $pending,
        BillableUser $billable,
        string $paymentMethodId,
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

        $this->settle($subscription, $pending, $billable);
    }

    /**
     * The curated payment method display order for a resolved country, or
     * the config default when the country is null (unresolved) or has no
     * curated entry of its own. Ordering only, never eligibility.
     *
     * @see CheckoutRegionResolver
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

        return array_values(array_filter(
            $order,
            fn (mixed $type): bool => is_string($type) && PaymentMethodType::tryFrom($type) !== null,
        ));
    }

    /**
     * Settles the subscription this checkout created, found by the id stored
     * on its own pending row. Never the billable's newest subscription, which
     * may belong to an entirely different workspace.
     */
    private function settleFromPendingSubscription(): void
    {
        $pending = $this->pendingReservation();

        if (! $pending instanceof TenantProvision) {
            $this->paymentError = __('numerosis::billing.checkout.confirmation_failed');

            return;
        }

        $billable = $this->billableFor($pending);

        if (! $billable instanceof BillableUser) {
            return;
        }

        $subscription = $pending->stripe_subscription_id !== null
            ? $billable->subscriptions()->where('stripe_id', $pending->stripe_subscription_id)->first()
            : null;

        if (! $subscription instanceof Subscription || ! $this->hasSettled($subscription)) {
            $this->paymentError = __('numerosis::billing.checkout.confirmation_failed');

            return;
        }

        $this->settle($subscription, $pending, $billable);
    }

    /**
     * The billable paying for a reservation, or null with `$paymentError`
     * already set.
     *
     * `#[Locked]` on `$pendingDomain` stops the client changing the value;
     * only {@see AssertReservationIsOwned} proves the reservation belongs to
     * whoever is paying.
     */
    private function billableFor(TenantProvision $pending): ?BillableUser
    {
        try {
            return AssertReservationIsOwned::run($pending);
        } catch (CheckoutSessionExpired $e) {
            $this->paymentError = $e->getMessage();

            return null;
        }
    }

    /** The reservation this component mounted for, by its `#[Locked]` domain. */
    private function pendingReservation(): ?TenantProvision
    {
        $pendingClass = Numerosis::model(TenantProvision::class);

        /** @var TenantProvision|null $pending */
        $pending = $pendingClass::find($this->pendingDomain);

        return $pending;
    }

    private function settle(Subscription $subscription, TenantProvision $pending, BillableUser $billable): void
    {
        SettleCheckout::run($pending, $subscription, $billable->stripeId(), (string) $billable->getKey());

        // The wizard's session-persisted step state is only useful while a
        // registration is in progress. A no-op when Checkout was reached
        // standalone, since nothing set the key.
        session()->forget(SessionKey::RegistrationWizardState->value);

        $this->redirectRoute(RouteNames::tenantsMine());
    }

    public function render(): View
    {
        return view('numerosis::livewire.billing.checkout');
    }
}

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
use Nvade\Numerosis\Actions\Billing\Checkout\LoadCheckoutContext;
use Nvade\Numerosis\Actions\Billing\Checkout\ResolveSavedPaymentMethod;
use Nvade\Numerosis\Actions\Billing\Checkout\ResolveSetupIntent;
use Nvade\Numerosis\Actions\Billing\Checkout\SettleCheckout;
use Nvade\Numerosis\Actions\Billing\Promotions\ValidatePromotionCode;
use Nvade\Numerosis\Actions\Billing\SyncBillingAddress;
use Nvade\Numerosis\Concerns\Billing\ConfirmsPayments;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Contracts\Billing\CheckoutRegionResolver;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Data\Billing\Checkout\CheckoutContext;
use Nvade\Numerosis\Data\Billing\PromotionData;
use Nvade\Numerosis\Enums\FetchState;
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Exceptions\Billing\CheckoutAlreadyCompleted;
use Nvade\Numerosis\Exceptions\Billing\CheckoutSessionExpired;
use Nvade\Numerosis\Exceptions\Billing\PromotionCodeUnavailable;
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

    /**
     * Client input: what the customer typed, or what a marketing link
     * pre-filled. Never trusted beyond being sent to Stripe for an answer.
     */
    public string $promotionCode = '';

    #[Locked]
    public ?PromotionData $appliedPromotion = null;

    public ?string $promotionError = null;

    public function mount(string $domain, Request $request, bool $embedded = false): void
    {
        $this->pendingDomain = $domain;
        $this->embedded = $embedded;
        $this->checkoutPublishableKey = Config::string('cashier.key');

        $context = LoadCheckoutContext::run($domain, $request);

        $this->detectedCountry = $context->detectedCountry;
        $this->paymentMethodOrder = $context->paymentMethodOrder;
        $this->customerEmail = $context->customerEmail;
        $this->vatNumber = $context->vatNumber;
        $this->savedBillingAddress = $context->savedBillingAddress;
        $this->savedBillingFetchState = $context->savedBillingFetchState;
        $this->savedPaymentMethods = $context->savedPaymentMethods;
        $this->savedPaymentMethodsFetchState = $context->savedPaymentMethodsFetchState;

        $this->resume($context);

        // A `?promo=` link pre-fills and is then validated like anything typed:
        // the query string decides what to try, never what is granted.
        $fromLink = $request->query('promo');

        $stored = $this->pendingReservation()?->promotion_code;

        $this->promotionCode = $stored ?? (is_string($fromLink) ? $fromLink : '');

        if ($this->promotionCode !== '') {
            $this->applyPromotionCode();
        }
    }

    /**
     * Validated through Stripe, stored on the reservation, and shown with the
     * discount Stripe reported. `CreateInlineSubscription` asks again before
     * the charge, so what is displayed here is never what is billed from.
     */
    public function applyPromotionCode(): void
    {
        $this->promotionError = null;
        $this->appliedPromotion = null;

        $pending = $this->pendingReservation();

        if (! $pending instanceof TenantProvision) {
            $this->promotionError = __('numerosis::billing.checkout.session_expired');

            return;
        }

        $billable = $this->billableFor($pending);

        if (! $billable instanceof BillableUser) {
            return;
        }

        try {
            $this->appliedPromotion = ValidatePromotionCode::run(
                $this->promotionCode,
                $billable,
                resolve(PaymentPlanRepository::class)->findBySlug((string) $pending->payment_plan),
                $pending->billing_cycle,
            );
        } catch (PromotionCodeUnavailable $e) {
            $this->promotionError = $e->getMessage();
            $pending->update(['promotion_code' => null]);

            return;
        }

        $pending->update(['promotion_code' => $this->appliedPromotion->code]);
    }

    public function removePromotionCode(): void
    {
        $this->promotionCode = '';
        $this->promotionError = null;
        $this->appliedPromotion = null;

        $this->pendingReservation()?->update(['promotion_code' => null]);
    }

    /**
     * Picks the checkout back up where it was left, or settles it when it
     * already succeeded.
     */
    private function resume(CheckoutContext $context): void
    {
        if ($context->error !== null) {
            $this->paymentError = $context->error;

            return;
        }

        if ($context->resumed?->alreadySucceeded() === true) {
            $this->settleFromPendingSubscription();

            return;
        }

        $this->checkoutClientSecret = $context->resumed?->clientSecret;
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

    /**
     * The one road to a charge, so no branch picks its own guards: the payer
     * owns the reservation, and the reservation has not been paid for already.
     */
    private function createSubscriptionAndSettle(
        TenantProvision $pending,
        BillableUser $billable,
        string $paymentMethodId,
    ): void {
        try {
            AssertReservationIsOwned::run($pending);
            AssertPendingReservationIsFresh::run($pending, $billable);
        } catch (CheckoutAlreadyCompleted) {
            $this->settleFromPendingSubscription();

            return;
        } catch (ShowsMessageToUser $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

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

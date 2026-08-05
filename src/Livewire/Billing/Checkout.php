<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Billing;

use Illuminate\Support\Facades\Config;
use Illuminate\View\View;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Nvade\Numerosis\Actions\Billing\Checkout\AssertPendingReservationIsFresh;
use Nvade\Numerosis\Actions\Billing\Checkout\CreateInlineSubscription;
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
use Nvade\Numerosis\Features\Ui\AccountPagesFeature;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\Numerosis\Support\Routes\RouteNames;

/**
 * The single implementation of the inline-checkout protocol, reached two ways:
 * standalone at /checkout/{domain} (resuming a checkout a refresh or a direct
 * visit would otherwise lose), and embedded by the registration wizard's
 * Payment step, which no longer carries a subscribe()/confirmed() of its own
 * and only resolves the reserved domain to hand over. See ConfirmsPayments
 * for the 3DS half of the protocol.
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

    public function mount(string $domain, bool $embedded = false): void
    {
        $this->pendingDomain = $domain;
        $this->embedded = $embedded;
        $this->checkoutPublishableKey = Config::string('cashier.key');

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
            // subscription — a double-click, or a retry after the browser
            // never saw the first response. Settle from what already exists
            // instead of surfacing a refusal for something that succeeded.
            $this->settleFromPendingSubscription();

            return;
        } catch (ShowsMessageToUser $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        // ResolveSetupIntent proves the row belongs to whoever is asking, but
        // not that it is the row *this component was mounted for* — and
        // $setupIntentId is client input, while settle() below re-reads by
        // $pendingDomain. A user holding two reservations could otherwise
        // confirm domain-a's SetupIntent here and have domain-b provisioned
        // from it, leaving domain-a still resumable off the same
        // subscription: two tenants, one payment.
        if ($resolved->pending->domain !== $this->pendingDomain) {
            $this->paymentError = __('billing.checkout.session_expired');

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
            $this->paymentError = __('billing.checkout.session_expired');

            return;
        }

        $pendingClass = Numerosis::model(PendingTenantProvision::class);

        /** @var PendingTenantProvision|null $pending */
        $pending = $pendingClass::find($this->pendingDomain);

        if (! $pending || $pending->global_id !== $billable->global_id) {
            $this->paymentError = __('billing.checkout.session_expired');

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
     * Resolves the subscription to settle by the id CreateInlineSubscription
     * stamped onto this pending row — never `latestSubscription()`, which
     * has no link to $pendingDomain and would settle whatever subscription
     * (possibly an unrelated, already-active one) happens to be newest for
     * this billable. See .claude/rules/billing-checkout.md.
     */
    private function settleFromPendingSubscription(): void
    {
        $pendingClass = Numerosis::model(PendingTenantProvision::class);

        /** @var PendingTenantProvision|null $pending */
        $pending = $pendingClass::find($this->pendingDomain);
        $billable = GetAuthenticatedUser::run();

        $subscription = $pending?->stripe_subscription_id !== null && $billable instanceof CentralUser
            ? $billable->subscriptions()->where('stripe_id', $pending->stripe_subscription_id)->first()
            : null;

        if (! $subscription || ! $this->hasSettled($subscription)) {
            $this->paymentError = __('billing.checkout.confirmation_failed');

            return;
        }

        $this->settle($subscription);
    }

    private function settle(Subscription $subscription): void
    {
        $pendingClass = Numerosis::model(PendingTenantProvision::class);

        /** @var PendingTenantProvision|null $pending */
        $pending = $pendingClass::find($this->pendingDomain);
        $billable = GetAuthenticatedUser::run();

        // Re-derived rather than assumed, behind #[Locked] rather than
        // instead of it: the lock is a Livewire-level guarantee, the
        // ownership rule is a domain one, and both entry points into settle()
        // must hold it. See .claude/rules/billing-checkout.md.
        if (! $pending || ! $billable instanceof CentralUser || $pending->global_id !== $billable->global_id) {
            $this->paymentError = __('billing.checkout.session_expired');

            return;
        }

        SettleCheckout::run($pending, $subscription, $billable->stripe_id, (string) $billable->id);

        // The registration wizard's session-persisted step state (see
        // Registration::showStep() in Part 2 of this plan) is only useful
        // while a registration is in progress. Clearing it here is a no-op
        // when Checkout was reached standalone (nothing set the key).
        session()->forget('registration.wizard_state');

        $this->redirectRoute(Features::enabled(AccountPagesFeature::NAME) ? RouteNames::tenantsMine() : RouteNames::home());
    }

    public function render(): View
    {
        return view('numerosis::livewire.billing.checkout');
    }
}

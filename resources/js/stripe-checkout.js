import { loadStripe } from '@stripe/stripe-js';
import { buildAppearance, watchAppearance } from './stripe-appearance.js';

/**
 * Owns the Stripe Payment Element's whole lifecycle: mount, confirm, and the
 * second confirmPayment() round trip a 3DS challenge can force. The mount
 * container is wire:ignore in the Blade template — Livewire must never
 * re-render into it, or the iframe (and whatever the customer typed) is
 * gone with no error. See custom-checkout.md, "Frontend".
 */
document.addEventListener('alpine:init', () => {
  Alpine.data('stripeCheckout', (clientSecret, publishableKey, returnUrl, declineCodes, customerEmail, savedBillingAddress, savedPaymentMethods) => ({
    stripe: null,
    elements: null,
    isSubmitting: false,
    errorMessage: null,
    stopWatchingAppearance: null,
    elementReady: false,
    addressElementReady: false,
    mode: savedPaymentMethods && savedPaymentMethods.length > 0 ? 'saved' : 'new',
    selectedPaymentMethodId: savedPaymentMethods && savedPaymentMethods.length > 0 ? savedPaymentMethods[0].id : null,

    /**
     * Stripe's error.message is written for developers ("Your card was
     * declined." with no next step). error.decline_code (falling back to
     * error.code) maps through lang/en/billing.php's decline_codes to
     * customer-grade copy; unmapped codes keep Stripe's own message.
     */
    friendlyError(error) {
      const key = error.decline_code || error.code;

      return (key && declineCodes[key]) || error.message;
    },

    async init() {
      this.stripe = await loadStripe(publishableKey);

      if (!this.stripe) {
        this.errorMessage = 'Could not load the payment form. Please refresh and try again.';

        return;
      }

      this.elements = this.stripe.elements({
        clientSecret,
        appearance: buildAppearance(),
      });

      // The customer already authenticated with this app; offering to also
      // create a separate Stripe Link identity mid-signup is redundant
      // friction, so the Link save-info prompt is off. Every other
      // automatic payment method stays — this only touches Link's UI, not
      // payment_method_types (never pinned, see custom-checkout.md,
      // "Designing for more payment methods"). Passing the known email
      // saves Stripe from asking for it again.
      const paymentElement = this.elements.create('payment', {
        wallets: { link: 'never' },
        defaultValues: customerEmail ? { billingDetails: { email: customerEmail } } : undefined,
      });
      paymentElement.mount(this.$refs.paymentElement);
      paymentElement.on('ready', () => {
        this.elementReady = true;
      });

      // Same elements group as the Payment Element, so confirmSetup()
      // attaches this address to the PaymentMethod's billing_details with no
      // extra client payload.
      const addressElement = this.elements.create('address', {
        mode: 'billing',
        defaultValues: savedBillingAddress ?? undefined,
      });
      addressElement.mount(this.$refs.addressElement);
      addressElement.on('ready', () => {
        this.addressElementReady = true;
      });

      this.stopWatchingAppearance = watchAppearance((appearance) => {
        this.elements.update({ appearance });
      });

      // Fired by Payment::subscribe() when CreateInlineSubscription catches
      // Laravel\Cashier\Exceptions\IncompletePayment — the SetupIntent was
      // confirmed, but the first invoice still needs a 3DS challenge Stripe
      // could not resolve upfront.
      this.$wire.on('requires-action', async ({ clientSecret: paymentClientSecret }) => {
        const { error } = await this.stripe.confirmPayment({
          clientSecret: paymentClientSecret,
          confirmParams: { return_url: returnUrl },
          redirect: 'if_required',
        });

        if (error) {
          this.errorMessage = this.friendlyError(error);
          this.isSubmitting = false;

          return;
        }

        this.$wire.confirmed();
      });
    },

    destroy() {
      if (this.stopWatchingAppearance) {
        this.stopWatchingAppearance();
      }
    },

    async submit() {
      if (this.isSubmitting) {
        return;
      }

      this.isSubmitting = true;
      this.errorMessage = null;

      const { error, setupIntent } = await this.stripe.confirmSetup({
        elements: this.elements,
        confirmParams: { return_url: returnUrl },
        redirect: 'if_required',
      });

      if (error) {
        this.errorMessage = this.friendlyError(error);
        this.isSubmitting = false;

        return;
      }

      // Redirect-flavoured methods (iDEAL, Bancontact) never reach this line
      // — the browser has already left for the bank's page, and comes back
      // through CompleteRedirectCheckout instead.
      this.$wire.subscribe(setupIntent.id);
    },

    submitSaved(paymentMethodId) {
      if (this.isSubmitting) {
        return;
      }

      this.isSubmitting = true;
      this.errorMessage = null;

      this.$wire.subscribeWithSavedPaymentMethod(paymentMethodId);
    },
  }));
});

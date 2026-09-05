import { loadStripe } from '@stripe/stripe-js';

/**
 * 3DS confirmation with no mounted Element: the marketplace charges a card
 * already on file, so there is no Payment Element to collect and nothing to
 * mount beyond this wrapper. Listens for the same 'requires-action' event
 * ConfirmsPayments::handleIncompletePayment() dispatches, then hands the
 * result back to $wire.confirmed(). See
 * .claude/plans/archive/module-marketplace.md, "Marketplace page".
 */
document.addEventListener('alpine:init', () => {
  Alpine.data('stripeConfirm', (publishableKey, declineCodes) => ({
    stripe: null,
    errorMessage: null,

    friendlyError(error) {
      const key = error.decline_code || error.code;

      return (key && declineCodes[key]) || error.message;
    },

    async init() {
      this.stripe = await loadStripe(publishableKey);

      this.$wire.on('requires-action', async ({ clientSecret }) => {
        if (!this.stripe) {
          this.errorMessage = 'Could not load Stripe. Please refresh and try again.';

          return;
        }

        const { error } = await this.stripe.confirmPayment({
          clientSecret,
          redirect: 'if_required',
        });

        if (error) {
          this.errorMessage = this.friendlyError(error);

          return;
        }

        this.errorMessage = null;
        this.$wire.confirmed();
      });
    },
  }));
});

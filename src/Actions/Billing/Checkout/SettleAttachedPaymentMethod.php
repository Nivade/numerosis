<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Laravel\Cashier\Cashier;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Billing\SyncBillingAddress;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Stripe\Exception\ApiErrorException;

/**
 * Settles one open checkout if the PaymentMethod Stripe just attached is the
 * one its SetupIntent generated. Returns whether this checkout was the match,
 * so a caller walking a customer's open checkouts stops at the first.
 *
 * Reached from `payment_method.attached` and not from `setup_intent.succeeded`:
 * for a redirect method Stripe never repoints the SetupIntent at the reusable
 * PaymentMethod it creates, and offers no way to look a setup attempt up
 * directly. Matching therefore runs per candidate reservation.
 *
 * @method static bool run(PendingTenantProvision $pending, BillableUser $billable, string $paymentMethodId)
 */
class SettleAttachedPaymentMethod
{
    use AsAction;

    public function handle(PendingTenantProvision $pending, BillableUser $billable, string $paymentMethodId): bool
    {
        if ($pending->stripe_setup_intent_id === null) {
            return false;
        }

        $setupIntent = Cashier::stripe()->setupIntents->retrieve(
            $pending->stripe_setup_intent_id,
            ['expand' => ['payment_method']],
        );

        $paymentMethod = ResolveAttachedPaymentMethod::run($setupIntent);

        if ($paymentMethod === null || $paymentMethod->id !== $paymentMethodId) {
            return false;
        }

        SyncBillingAddress::run($billable, $paymentMethod);

        try {
            FinalizeCheckoutSubscription::run($pending, $paymentMethod, $billable);
        } catch (IncompletePayment $e) {
            // The first invoice needs a 3DS challenge and there is no browser
            // to show it in. Acknowledge; the customer is prompted on their
            // next visit.
            report($e);
        } catch (ApiErrorException $e) {
            report($e);
        }

        return true;
    }
}

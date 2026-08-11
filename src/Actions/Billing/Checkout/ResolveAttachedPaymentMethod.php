<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;

/**
 * Finds the reusable payment method a SetupIntent produced, or null.
 *
 * Cards attach synchronously and are returned directly. Redirect methods
 * such as iDEAL and Bancontact never attach: Stripe instead creates a
 * separate SEPA direct-debit method, reachable only through the setup
 * attempt, which is what this falls back to.
 *
 * @method static ?PaymentMethod run(SetupIntent $setupIntent)
 */
class ResolveAttachedPaymentMethod
{
    use AsAction;

    public function handle(SetupIntent $setupIntent): ?PaymentMethod
    {
        $paymentMethod = $setupIntent->payment_method instanceof PaymentMethod ? $setupIntent->payment_method : null;

        if (! $paymentMethod instanceof PaymentMethod) {
            return null;
        }

        if ($paymentMethod->customer === $setupIntent->customer) {
            return $paymentMethod;
        }

        $generatedId = $this->generatedPaymentMethodId($setupIntent->id, $paymentMethod->type);

        if ($generatedId === null) {
            return null;
        }

        $generated = Cashier::stripe()->paymentMethods->retrieve($generatedId);

        return $generated->customer === $setupIntent->customer ? $generated : null;
    }

    private function generatedPaymentMethodId(string $setupIntentId, string $type): ?string
    {
        $attempts = Cashier::stripe()->setupAttempts->all([
            'setup_intent' => $setupIntentId,
            'limit' => 1,
        ]);

        $attempt = $attempts->data[0] ?? null;

        if ($attempt === null || $attempt->status !== 'succeeded') {
            return null;
        }

        $details = $attempt->payment_method_details->{$type} ?? null;
        $generated = $details->generated_sepa_debit ?? null;

        return is_string($generated) ? $generated : null;
    }
}

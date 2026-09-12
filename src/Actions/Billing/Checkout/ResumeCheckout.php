<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Billing\Checkout\ResumedCheckout;
use Nvade\Numerosis\Exceptions\Billing\CheckoutSessionExpired;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;
use Stripe\Exception\ApiErrorException;

/**
 * Reopens a checkout against its stored SetupIntent, so a refresh or a
 * direct visit to `/checkout/{domain}` resumes, never starting over.
 *
 * Ownership is checked the same way {@see ResolveSetupIntent} checks it.
 *
 * @method static ResumedCheckout run(string $domain)
 */
class ResumeCheckout
{
    use AsAction;

    public function handle(string $domain): ResumedCheckout
    {
        /** @var TenantProvision|null $pending */
        $pending = Numerosis::model(TenantProvision::class)::find($domain);

        if (! $pending) {
            throw new CheckoutSessionExpired(__('numerosis::billing.checkout.session_expired'));
        }

        $billable = AssertReservationIsOwned::run($pending);

        if (! $pending->stripe_setup_intent_id) {
            throw new CheckoutSessionExpired(__('numerosis::billing.checkout.session_expired'));
        }

        try {
            $setupIntent = Cashier::stripe()->setupIntents->retrieve($pending->stripe_setup_intent_id);
        } catch (ApiErrorException) {
            throw new CheckoutSessionExpired(__('numerosis::billing.checkout.session_expired'));
        }

        return new ResumedCheckout($pending, (string) $setupIntent->client_secret, $setupIntent->status === 'succeeded');
    }
}

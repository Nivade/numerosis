<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Exceptions\Billing\CheckoutSessionExpired;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Services\Billing\Checkout\ResumedCheckout;
use Nvade\Numerosis\Support\Numerosis;
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

    public function __construct(private readonly BillableResolver $billables) {}

    public function handle(string $domain): ResumedCheckout
    {
        /** @var PendingTenantProvision|null $pending */
        $pending = Numerosis::model(PendingTenantProvision::class)::find($domain);

        if (! $pending) {
            throw new CheckoutSessionExpired(__('numerosis::billing.checkout.session_expired'));
        }

        $billable = $this->billables->resolve();

        if (! $billable instanceof CentralUser || $billable->global_id !== $pending->global_id) {
            throw new CheckoutSessionExpired(__('numerosis::billing.checkout.foreign_session'));
        }

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

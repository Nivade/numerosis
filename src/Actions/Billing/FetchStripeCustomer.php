<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;

/**
 * The billable's Stripe customer, with `tax_ids` expanded — one retrieve
 * covering everything the checkout screen reads off it. `null` means the
 * lookup failed and has been reported; a billable with no Stripe customer at
 * all is the caller's `hasStripeId()` check, not this one's.
 *
 * @method static ?Customer run(BillableUser $billable)
 */
class FetchStripeCustomer
{
    use AsAction;

    public function handle(BillableUser $billable): ?Customer
    {
        try {
            return Cashier::stripe()->customers->retrieve(
                $billable->stripeIdOrFail(),
                ['expand' => ['tax_ids']],
            );
        } catch (ApiErrorException $e) {
            report($e);

            return null;
        }
    }
}

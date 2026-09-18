<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Laravel\Cashier\Exceptions\InvalidCustomer;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;

/**
 * The billable's Stripe customer, with `tax_ids` expanded, in one retrieve
 * covering everything the checkout screen reads off it. `null` means the
 * lookup failed and has been reported; a billable with no Stripe customer at
 * all never reaches here, since the caller's own `hasStripeId()` check
 * already excludes it.
 *
 * @method static ?Customer run(BillableUser $billable)
 */
class FetchStripeCustomer
{
    use AsAction;

    public function handle(BillableUser $billable): ?Customer
    {
        try {
            return $billable->asStripeCustomer(['tax_ids']);
        } catch (ApiErrorException|InvalidCustomer $e) {
            report($e);

            return null;
        }
    }
}

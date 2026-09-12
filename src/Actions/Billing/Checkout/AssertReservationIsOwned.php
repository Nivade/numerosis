<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Exceptions\Billing\CheckoutSessionExpired;
use Nvade\Numerosis\Models\Central\TenantProvision;

/**
 * The billable a checkout is running as, refused unless it owns the
 * reservation. One rule for every checkout entry point, so a new one cannot
 * reach a reservation belonging to somebody else.
 *
 * @method static BillableUser run(TenantProvision $pending)
 */
class AssertReservationIsOwned
{
    use AsAction;

    public function __construct(private readonly BillableResolver $billables) {}

    public function handle(TenantProvision $pending): BillableUser
    {
        $billable = $this->billables->resolve();

        if (! $billable instanceof BillableUser || $billable->getGlobalIdentifierKey() !== $pending->global_id) {
            throw new CheckoutSessionExpired(__('numerosis::billing.checkout.foreign_session'));
        }

        return $billable;
    }
}

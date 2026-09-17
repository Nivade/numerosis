<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Exceptions\Tenancy\TenantClosureBlocked;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * @method static void run(Tenant $tenant, bool $acknowledgeOutstandingBalance = false)
 */
class AssertTenantClosable
{
    use AsAction;

    /**
     * Cancelling at period end leaves a failing invoice behind, still owed and
     * no longer attached to anything the customer can see, so an unsettled
     * balance has to be named before it is closed away.
     */
    public function handle(Tenant $tenant, bool $acknowledgeOutstandingBalance = false): void
    {
        if ($acknowledgeOutstandingBalance) {
            return;
        }

        throw_if(
            $tenant->subscriptions()->withUnpaidInvoice()->exists(),
            new TenantClosureBlocked(__('This workspace has an unpaid invoice. Confirm you want to close it anyway, or settle the invoice first.')),
        );
    }
}

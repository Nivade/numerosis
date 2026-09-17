<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Exceptions\Tenancy\OwnershipTransferBlocked;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Both the nomination and the acceptance run this, so a workspace that fell
 * into dunning between the two is refused at the second gate as well.
 *
 * @method static void run(Tenant $tenant, Membership $target)
 */
class AssertOwnershipTransferable
{
    use AsAction;

    public function handle(Tenant $tenant, Membership $target): void
    {
        throw_if(
            $target->tenant_id !== $tenant->id,
            new OwnershipTransferBlocked(__('That member belongs to a different workspace.')),
        );

        throw_if(
            $target->joined_at === null,
            new OwnershipTransferBlocked(__('Ownership can only move to a member who has accepted their invitation.')),
        );

        // A customer whose invoice is failing keeps its payment method with
        // it, so renaming it mid-dunning changes who the retries are addressed
        // to without changing what they charge.
        throw_if(
            $tenant->subscriptions()->withUnpaidInvoice()->exists(),
            new OwnershipTransferBlocked(__('This workspace has an unpaid invoice. Settle it before transferring ownership.')),
        );
    }
}

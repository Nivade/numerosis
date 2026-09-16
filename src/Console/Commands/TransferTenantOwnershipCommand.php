<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Nvade\Numerosis\Actions\Tenancy\TransferTenantOwnership;
use Nvade\Numerosis\Exceptions\Tenancy\OwnershipTransferBlocked;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\OwnershipNomination;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * The support path for a workspace whose owner is unreachable, so it skips the
 * nomination the owner would otherwise have to send.
 */
#[Description('Reassign a tenant to a new owner without the nominee accepting')]
#[Signature('tenancy:transfer-ownership
                            {tenant : The tenant id}
                            {email : Email address of the member taking ownership}')]
class TransferTenantOwnershipCommand extends Command
{
    public function handle(): int
    {
        $tenantId = (string) $this->argument('tenant');
        $email = (string) $this->argument('email');

        $tenant = Numerosis::model(Tenant::class)::find($tenantId);

        if (! $tenant instanceof Tenant) {
            $this->error("No tenant [{$tenantId}].");

            return self::FAILURE;
        }

        $user = Numerosis::model(CentralUser::class)::where('email', $email)->first();

        if (! $user instanceof CentralUser) {
            $this->error("No central user with the address [{$email}].");

            return self::FAILURE;
        }

        $membership = Membership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('global_user_id', $user->global_id)
            ->first();

        if (! $membership instanceof Membership) {
            $this->error("[{$email}] is not a member of [{$tenantId}].");

            return self::FAILURE;
        }

        $previous = $tenant->owner()?->email;

        if (! $this->confirm("Make {$email} the owner of {$tenantId}, replacing ".($previous ?? 'nobody').'?')) {
            return self::FAILURE;
        }

        try {
            TransferTenantOwnership::run($tenant, $membership);
        } catch (OwnershipTransferBlocked $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        Numerosis::model(OwnershipNomination::class)::query()
            ->where('tenant_id', $tenant->getKey())
            ->delete();

        // No `performedOn($tenant)`: `activity_log.subject_id` is an integer
        // column and a tenant key is a string.
        activity()
            ->withProperties(['tenant_id' => $tenant->getKey(), 'from' => $previous, 'to' => $email])
            ->log("Ownership of {$tenantId} reassigned from ".($previous ?? 'nobody')." to {$email} by staff");

        $this->info("{$email} now owns {$tenantId}.");

        return self::SUCCESS;
    }
}

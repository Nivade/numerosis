<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use DomainException;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningCancelled;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Numerosis;

class MarkProvisionCancelled
{
    use AsAction;

    public function handle(string $domain): void
    {
        /** @var PendingTenantProvision|null $pending */
        $pending = Numerosis::model(PendingTenantProvision::class)::firstWhere('domain', $domain);

        throw_unless($pending, DomainException::class, "Could not find a pending tenant reservation for domain: {$domain}");

        $ownerId = $pending->global_id;

        $pending->delete();

        event(new TenantProvisioningCancelled(globalId: $ownerId));
    }
}

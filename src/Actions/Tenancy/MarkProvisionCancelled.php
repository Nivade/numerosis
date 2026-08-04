<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Nvade\Numerosis\Events\Tenancy\TenantProvisioningCancelled;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use DomainException;
use Exception;
use Lorisleiva\Actions\Concerns\AsAction;

class MarkProvisionCancelled
{
    use AsAction;

    public function handle(string $domain): void
    {
        try {
            $pending = PendingTenantProvision::where('domain', $domain)
                ->firstOrFail();
        } catch (Exception $e) {
            throw new DomainException("Could not find a pending tenant reservation for domain: {$domain}");
        }

        $ownerId = $pending->global_id;

        $pending->delete();

        event(new TenantProvisioningCancelled(globalId: $ownerId));
    }
}

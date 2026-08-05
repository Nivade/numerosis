<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use DomainException;
use Exception;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningCancelled;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Support\Numerosis;

class MarkProvisionCancelled
{
    use AsAction;

    public function handle(string $domain): void
    {
        $pendingClass = Numerosis::model(PendingTenantProvision::class);

        try {
            /** @var PendingTenantProvision $pending */
            $pending = $pendingClass::where('domain', $domain)
                ->firstOrFail();
        } catch (Exception $e) {
            throw new DomainException("Could not find a pending tenant reservation for domain: {$domain}");
        }

        $ownerId = $pending->global_id;

        $pending->delete();

        event(new TenantProvisioningCancelled(globalId: $ownerId));
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningStarted;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;

class MarkProvisionInProgress
{
    use AsAction;

    public function handle(TenantProvisionData $registration): void
    {
        $pendingClass = Numerosis::model(TenantProvision::class);

        $pendingClass::updateOrCreate(
            ['slug' => $registration->slug],
            [
                'name' => $registration->name,
                'global_id' => $registration->global_id,
                'status' => TenantProvisionStatus::Provisioning,
                'failed_at' => null,
                'error' => null,
            ],
        );

        event(new TenantProvisioningStarted($registration->slug, $registration->global_id));
    }
}

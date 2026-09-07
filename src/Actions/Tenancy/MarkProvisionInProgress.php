<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningStarted;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Numerosis;

class MarkProvisionInProgress
{
    use AsAction;

    public function handle(TenantRegistrationData $registration): void
    {
        $pendingClass = Numerosis::model(PendingTenantProvision::class);

        $pendingClass::updateOrCreate(
            ['domain' => $registration->domain],
            [
                'company_name' => $registration->company_name,
                'global_id' => $registration->global_id,
                'status' => TenantProvisionStatus::Provisioning,
                'failed_at' => null,
                'error' => null,
            ],
        );

        event(new TenantProvisioningStarted($registration->domain, $registration->global_id));
    }
}

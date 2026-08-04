<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\TenantProvisionStatus;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Lorisleiva\Actions\Concerns\AsAction;

class MarkProvisionInProgress
{
    use AsAction;

    public function handle(TenantRegistrationData $registration): void
    {
        PendingTenantProvision::updateOrCreate(
            ['domain' => $registration->domain],
            [
                'company_name' => $registration->company_name,
                'global_id' => $registration->global_id,
                'status' => TenantProvisionStatus::Provisioning,
                'failed_at' => null,
                'error' => null,
            ],
        );
    }
}

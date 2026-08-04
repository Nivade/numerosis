<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Nvade\Numerosis\Enums\TenantProvisionStatus;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Lorisleiva\Actions\Concerns\AsAction;

class MarkProvisionFailed
{
    use AsAction;

    public function handle(string $domain, string $error): void
    {
        PendingTenantProvision::where('domain', $domain)->update([
            'status' => TenantProvisionStatus::Failed,
            'failed_at' => now(),
            'error' => $error,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\TenantProvisionStatus;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Support\Numerosis;

class MarkProvisionFailed
{
    use AsAction;

    public function handle(string $domain, string $error): void
    {
        Numerosis::model(PendingTenantProvision::class)::where('domain', $domain)->update([
            'status' => TenantProvisionStatus::Failed,
            'failed_at' => now(),
            'error' => $error,
        ]);
    }
}

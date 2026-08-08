<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Events\Billing\PaymentSettled;
use Nvade\Numerosis\Models\Central\Tenant;

class RestoreTenant
{
    use AsAction;

    public function handle(Tenant $tenant): void
    {
        if (! $tenant->isSuspended()) {
            return;
        }

        $tenant->update(['suspended_at' => null]);

        $owner = $tenant->owner();

        if ($owner) {
            event(new PaymentSettled($tenant, $owner->id));
        }
    }
}

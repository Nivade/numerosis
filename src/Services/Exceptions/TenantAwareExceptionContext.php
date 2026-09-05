<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Exceptions;

use Illuminate\Support\Facades\Auth;
use Nvade\Numerosis\Contracts\Exceptions\ProvidesExceptionContext;
use Nvade\Numerosis\Models\User as NumerosisUser;
use Stancl\Tenancy\Contracts\Tenant;
use Throwable;

class TenantAwareExceptionContext implements ProvidesExceptionContext
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        // Best-effort, never a hard dependency: an exception thrown during
        // bootstrap is reported before facades are available, and letting
        // that fail here would mask the error that actually broke boot.
        try {
            $tenantId = tenancy()->initialized && tenancy()->tenant instanceof Tenant
                ? (string) tenancy()->tenant->getTenantKey()
                : null;

            $user = Auth::user();

            return [
                'tenant_id' => $tenantId,
                'guard' => Auth::getDefaultDriver(),
                'user_global_id' => $user instanceof NumerosisUser ? $user->global_id : null,
            ];
        } catch (Throwable) {
            return [];
        }
    }
}

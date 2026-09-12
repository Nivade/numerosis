<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns\Tenancy;

use Closure;
use Nvade\Numerosis\Models\Central\Tenant;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * `Stancl\Tenancy\Database\Concerns\TenantRun::run()` has no `try`/`finally`,
 * so a throw inside its callback leaves the process initialized against that
 * tenant — the next job on the same worker then runs in the wrong tenant's
 * context. This restores the original tenant, or ends tenancy, unconditionally.
 */
trait RunsInTenant
{
    public function runInTenant(Tenant $tenant, Closure $callback): mixed
    {
        $original = tenancy()->tenant;

        tenancy()->initialize($tenant);

        try {
            return $callback($tenant);
        } finally {
            if ($original instanceof TenantContract) {
                tenancy()->initialize($original);
            } else {
                tenancy()->end();
            }
        }
    }
}

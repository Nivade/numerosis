<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns;

use Sentry\State\Scope;

use function Sentry\configureScope;

/**
 * Records the tenant on Sentry's scope from a queued job's `failed()`
 * handler.
 *
 * Compose this into any tenant-aware job whose failures are worth debugging.
 * Exception context alone cannot recover the tenant: by the time the queue
 * worker reports the failure, tenancy has already reverted — but the job's
 * own `failed()` runs first, which is what this relies on.
 */
trait TagsSentryScopeWithTenant
{
    protected function tagSentryScopeWithTenant(string $tenantKey): void
    {
        if (! app()->bound('sentry')) {
            return;
        }

        configureScope(fn (Scope $scope) => $scope->setTag('tenant_id', $tenantKey));
    }
}

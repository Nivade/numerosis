<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns\Tenancy;

use Sentry\State\Scope;

use function Sentry\configureScope;

/**
 * Records the tenant on Sentry's scope from a queued job's `failed()` handler.
 * Compose it into any tenant-aware job whose failures are worth debugging.
 * Exception context alone cannot recover the tenant, because tenancy has
 * reverted by the time the worker reports the failure; the job's own
 * `failed()` runs before that, which is what this relies on.
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

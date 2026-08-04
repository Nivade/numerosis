<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns;

use Sentry\State\Scope;

use function Sentry\configureScope;

/**
 * Used by a queued job's failed() handler to put the tenant on Sentry's
 * scope before Illuminate\Queue\Worker::runJob()'s automatic report() fires.
 * See .claude/rules/exception-handling.md — tenancy is already reverted by
 * the time that automatic report runs, so $exceptions->context() alone
 * cannot recover the tenant. Job::fail() calls the job's own failed()
 * before that automatic report, which is the ordering this relies on.
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

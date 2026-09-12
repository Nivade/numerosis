<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Testing;

use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;

/**
 * Gives `Tenant::create()` a physical database again, for tests that want a
 * working tenant rather than a provisioning run.
 *
 * Production has one path, the configured provisioning steps, and
 * `TenantCreated` is deliberately empty. A suite that creates tenants
 * directly is the case that costs: 63 tests broke here the day the event
 * stopped building databases.
 */
trait BuildsTenantDatabasesOnCreate
{
    protected function buildTenantDatabasesOnCreate(): void
    {
        Event::listen(TenantCreated::class, function (TenantCreated $event): void {
            /** @var TenantWithDatabase $tenant */
            $tenant = $event->tenant;

            if (! $this->shouldBuildDatabaseFor($tenant)) {
                return;
            }

            app()->call([new CreateDatabase($tenant), 'handle']);

            $this->afterTenantDatabaseCreated($tenant);
        });
    }

    /**
     * False when provisioning made this tenant: the pipeline's own database
     * steps would then find the work already done and record themselves as
     * run without having done anything.
     */
    protected function shouldBuildDatabaseFor(TenantWithDatabase $tenant): bool
    {
        return ! Numerosis::model(TenantProvision::class)::query()
            ->where('slug', $tenant->getTenantKey())
            ->exists();
    }

    /** Where a suite substitutes its own faster migrate-and-seed. */
    protected function afterTenantDatabaseCreated(TenantWithDatabase $tenant): void {}
}

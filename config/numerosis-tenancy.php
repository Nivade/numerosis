<?php

declare(strict_types=1);

use App\Actions\Tenancy\AddTenantOwner;
use App\Actions\Tenancy\CreateTenant;
use App\Actions\Tenancy\ProvisionTenant;
use App\Contracts\Tenancy\ProvisionsTenant;
use App\Contracts\Tenancy\TenantDomainPolicy;
use App\Services\Tenancy\DefaultTenantDomainPolicy;

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant Provisioning Pipeline
    |--------------------------------------------------------------------------
    |
    | Steps run in order, as links in ProvisionTenant's queued chain on the
    | `provisioning` queue, every time a tenant is provisioned. The first
    | entry must create/find the tenant and return it — every step after
    | that (including any you append) is invoked as
    | ::run(Tenant $tenant, TenantProvisionData $data). LinkTenantSubscription
    | (only when a Stripe subscription id is present) and
    | FinalizeTenantProvisioning are appended automatically after these — they
    | cannot be reordered here, because FinalizeTenantProvisioning is the sole
    | emitter of the "provisioning finished" signal. Every step must be
    | idempotent: the whole list re-runs on retry.
    |
    | This is the one place business-level provisioning order is defined.
    | The separate physical-database segment (create/migrate/seed the tenant
    | database) lives in TenancyServiceProvider::$tenantCreatedJobs — kept
    | apart because those are raw queue jobs constructed `new $job($tenant)`,
    | not AsAction steps invoked `::run($tenant, $data)`, and because tests
    | override that list to swap in a fast template-clone stand-in. See
    | .claude/rules/tenant-provisioning.md.
    |
    */

    'provisioning' => [
        'steps' => [
            CreateTenant::class,
            AddTenantOwner::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Contract Bindings
    |--------------------------------------------------------------------------
    |
    | Swap any of these for your own implementation by changing the class
    | here — no provider edit required.
    |
    */

    'implementations' => [
        TenantDomainPolicy::class => DefaultTenantDomainPolicy::class,
        ProvisionsTenant::class => ProvisionTenant::class,
    ],

];

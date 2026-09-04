<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Events\Tenancy\TenantDomainReserved;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Tenant;
use RuntimeException;

class CreateTenantDomain
{
    use AsAction;

    /**
     * `$subdomain` is always the tenant's safe id/slug (also `tenants.id` and
     * the physical database name), regardless of mode — it is never the raw
     * value of a custom domain, which cannot safely be either of those (see
     * .ai/rules/tenant-provisioning.md's `id` bullet and
     * .ai/rules/identification-modes.md).
     *
     * Returns null under `IdentificationMode::Path`, which resolves tenants
     * purely by id and creates no `domains` row at all.
     */
    public function handle(Tenant $tenant, string $subdomain, ?string $customDomain = null): ?Domain
    {
        $mode = IdentificationMode::current();

        if (! $mode->usesDomainRecord()) {
            return null;
        }

        $domain = $tenant->domains()->firstOrCreate(
            ['id' => $subdomain],
            ['domain' => match ($mode) {
                IdentificationMode::Subdomain => $subdomain.'.'.Config::string('numerosis.domains.apex'),
                IdentificationMode::CustomDomain => $customDomain ?? throw new RuntimeException(
                    'IdentificationMode::CustomDomain requires a custom domain to create a tenant domain.'
                ),
                IdentificationMode::Path => $subdomain,
            }],
        );

        if ($domain->wasRecentlyCreated) {
            event(new TenantDomainReserved((string) $tenant->getTenantKey(), $domain->domain, $mode));
        }

        return $domain;
    }
}

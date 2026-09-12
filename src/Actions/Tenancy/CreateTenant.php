<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\ProvisioningStep;
use Nvade\Numerosis\Contracts\Tenancy\ReadsContributions;
use Nvade\Numerosis\Data\Tenancy\CustomDomainContribution;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;

/**
 * Creates the tenant row and its domain. Creates no database: a tenant
 * returned from here is not yet usable.
 *
 * No lock of its own any more. It used to take one because two chains could
 * reach it at once; the provision row is claimed before the chain is
 * dispatched now, so only one chain per slug ever runs.
 */
class CreateTenant implements ProvisioningStep, ReadsContributions
{
    use AsAction;

    /**
     * @return list<class-string<CustomDomainContribution>>
     */
    public static function reads(): array
    {
        return [CustomDomainContribution::class];
    }

    public function handle(TenantProvision $provision): void
    {
        $tenantClass = Numerosis::model(Tenant::class);

        /** @var Tenant|null $existing */
        $existing = $tenantClass::find($provision->slug);

        $tenant = $existing ?? $tenantClass::create([
            'id' => $provision->slug,
            'name' => $provision->name,
            'registration_date' => now(),
            'created_by' => $provision->global_id,
        ]);

        CreateTenantDomain::run(
            $tenant,
            $provision->slug,
            $provision->contribution(CustomDomainContribution::class)?->custom_domain,
        );
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\CreatesTenant;
use Nvade\Numerosis\Data\Tenancy\CustomDomainContribution;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;
use RuntimeException;

/**
 * Creates the tenant row and its domain: the first provisioning step, and the
 * only one that runs synchronously.
 *
 * Creates no database: that happens later in the provisioning chain, so a
 * tenant returned from here is not yet usable.
 *
 * @method static Tenant run(TenantProvisionData $registration)
 */
class CreateTenant implements CreatesTenant
{
    use AsAction;

    public function handle(TenantProvisionData $registration): Tenant
    {
        $tenantClass = Numerosis::model(Tenant::class);

        // A run that died halfway is finished by the next attempt.
        $tenant = Cache::lock("tenant-provision:{$registration->slug}", 10)->block(5, function () use ($registration, $tenantClass) {
            /** @var Tenant|null $existing */
            $existing = $tenantClass::find($registration->slug);

            // The provisioning chain creates the database. Letting tenancy's
            // pipeline fire too throws TenantDatabaseAlreadyExistsException.
            $tenant = $existing ?? $tenantClass::withoutEvents(function () use ($tenantClass, $registration): Tenant {
                /** @var Tenant */
                return $tenantClass::create([
                    'id' => $registration->slug,
                    'name' => $registration->name,
                    'registration_date' => now(),
                    'created_by' => $registration->global_id,
                ]);
            });

            CreateTenantDomain::run(
                $tenant,
                $registration->slug,
                $registration->contribution(CustomDomainContribution::class)?->custom_domain,
            );

            return $tenant;
        });

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException("Failed to create tenant: {$registration->slug}");
        }

        return $tenant;
    }
}

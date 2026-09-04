<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;
use RuntimeException;

/**
 * Creates the tenant row and its domain — the first provisioning step, and
 * the only one that runs synchronously.
 *
 * Creates no database: that happens later in the provisioning chain, so a
 * tenant returned from here is not yet usable.
 *
 * @method static Tenant run(TenantRegistrationData $registration)
 */
class CreateTenant
{
    use AsAction;

    public function handle(TenantRegistrationData $registration): Tenant
    {
        $tenantClass = Numerosis::model(Tenant::class);

        // Re-entrant: a run that died halfway is finished by the next attempt.
        $tenant = Cache::lock("tenant-provision:{$registration->domain}", 10)->block(5, function () use ($registration, $tenantClass) {
            /** @var Tenant|null $existing */
            $existing = $tenantClass::find($registration->domain);

            // The provisioning chain creates the database. Letting tenancy's
            // pipeline fire too throws TenantDatabaseAlreadyExistsException.
            $tenant = $existing ?? $tenantClass::withoutEvents(function () use ($tenantClass, $registration): Tenant {
                /** @var Tenant */
                return $tenantClass::create([
                    'id' => $registration->domain,
                    'name' => $registration->company_name,
                    'registration_date' => now(),
                    'created_by' => $registration->global_id,
                ]);
            });

            CreateTenantDomain::run($tenant, $registration->domain, $registration->custom_domain);

            return $tenant;
        });

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException("Failed to create tenant: {$registration->domain}");
        }

        return $tenant;
    }
}

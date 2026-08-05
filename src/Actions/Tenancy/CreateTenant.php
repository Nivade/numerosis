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
 * @method static Tenant run(TenantRegistrationData $registration)
 */
class CreateTenant
{
    use AsAction;

    public function handle(TenantRegistrationData $registration): Tenant
    {
        // Abstract; see Numerosis::model()'s docblock.
        $tenantClass = Numerosis::model(Tenant::class);

        // See .claude/rules/tenant-provisioning.md.
        $tenant = Cache::lock("tenant-provision:{$registration->domain}", 10)->block(5, function () use ($registration, $tenantClass) {
            // withoutEvents: ProvisionTenant's chain owns database creation
            // via its own CreateDatabase/MigrateDatabase/SeedTenantDatabase
            // links. Letting TenantCreated's queued pipeline fire too would
            // race a second CreateDatabase into
            // TenantDatabaseAlreadyExistsException.
            /** @var Tenant|null $existing */
            $existing = $tenantClass::find($registration->domain);

            $tenant = $existing ?? $tenantClass::withoutEvents(function () use ($tenantClass, $registration): Tenant {
                /** @var Tenant */
                return $tenantClass::create([
                    'id' => $registration->domain,
                    'name' => $registration->company_name,
                    'registration_date' => now(),
                    'created_by' => $registration->global_id,
                ]);
            });

            CreateTenantDomain::run($tenant, $registration->domain);

            return $tenant;
        });

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException("Failed to create tenant: {$registration->domain}");
        }

        return $tenant;
    }
}

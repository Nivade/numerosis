<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Models\Central\Tenant;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * @method static Tenant run(TenantRegistrationData $registration)
 */
class CreateTenant
{
    use AsAction;

    public function handle(TenantRegistrationData $registration): Tenant
    {
        // See .claude/rules/tenant-provisioning.md.
        $tenant = Cache::lock("tenant-provision:{$registration->domain}", 10)->block(5, function () use ($registration) {
            // withoutEvents: ProvisionTenant's chain owns database creation
            // via its own CreateDatabase/MigrateDatabase/SeedTenantDatabase
            // links. Letting TenantCreated's queued pipeline fire too would
            // race a second CreateDatabase into
            // TenantDatabaseAlreadyExistsException.
            $tenant = Tenant::find($registration->domain) ?? Tenant::withoutEvents(fn (): Tenant => Tenant::create([
                'id' => $registration->domain,
                'name' => $registration->company_name,
                'registration_date' => now(),
                'created_by' => $registration->global_id,
            ]));

            CreateTenantDomain::run($tenant, $registration->domain);

            return $tenant;
        });

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException("Failed to create tenant: {$registration->domain}");
        }

        return $tenant;
    }
}

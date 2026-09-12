<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Data\Tenancy\CustomDomainContribution;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * Provisions a tenant outside checkout.
 *
 * Until this existed the only ways in were the registration wizard, the Stripe
 * webhook and the local checkout shortcut, so "create a tenant without selling
 * anything" was reachable only from code. It queues the same chain those do —
 * the billing steps skip themselves, because nothing contributed billing.
 */
#[Description('Provision a tenant without going through checkout')]
#[Signature('tenancy:provision
                            {slug : The tenant id, which is also its subdomain label}
                            {--owner= : global_id of the central user who will own it}
                            {--name= : Display name, defaulting to the slug}
                            {--custom-domain= : Only under the custom-domain identification mode}')]
class ProvisionTenantCommand extends Command
{
    public function __construct(
        private readonly ProvisionsTenant $provisioning,
        private readonly TenantDomainPolicy $domainPolicy,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $slug = is_string($this->argument('slug')) ? $this->argument('slug') : '';
        $owner = is_string($this->option('owner')) ? $this->option('owner') : '';

        // Both checks: the policy covers domain format, reserved words and
        // the domains table, but a tenant row can exist without one, and
        // CreateTenant would then adopt it and attach this owner to somebody
        // else's tenant.
        if (Numerosis::model(Tenant::class)::whereKey($slug)->exists()) {
            $this->error("A tenant with id [{$slug}] already exists.");

            return self::FAILURE;
        }

        try {
            $this->domainPolicy->assertAvailable($slug);
        } catch (ShowsMessageToUser $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Checked here rather than left to AddTenantOwner, which would fail
        // inside a queued job with nobody watching.
        $exists = Numerosis::model(CentralUser::class)::where('global_id', $owner)->exists();

        if (! $exists) {
            $this->error("No central user has global_id [{$owner}].");

            return self::FAILURE;
        }

        $customDomain = $this->option('custom-domain');

        $this->provisioning->queue(new TenantProvisionData(
            slug: $slug,
            name: is_string($this->option('name')) && $this->option('name') !== ''
                ? $this->option('name')
                : $slug,
            global_id: $owner,
            contributions: is_string($customDomain) && $customDomain !== ''
                ? [new CustomDomainContribution($customDomain)]
                : [],
        ));

        $this->info("Queued provisioning for [{$slug}] on the `provisioning` queue.");
        $this->line('  php artisan queue:work --queue=provisioning');

        return self::SUCCESS;
    }
}

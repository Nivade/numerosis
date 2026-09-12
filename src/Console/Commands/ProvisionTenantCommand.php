<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Data\Tenancy\CustomDomainContribution;
use Nvade\Numerosis\Data\Tenancy\OwnerContribution;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
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
                            {--custom-domain= : Only under the custom-domain identification mode}
                            {--sync : Run the steps here and now instead of queueing them}')]
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

        // Checked up front because the failure is otherwise the fifth queued
        // job dying on `unknown function: SUBSTRING_INDEX()`, which names
        // neither the driver nor this command.
        $driver = Config::string('database.connections.'.Config::string('numerosis.tenancy.central_connection', 'central').'.driver', '');

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->error("The central connection uses the [{$driver}] driver. Tenancy needs CREATE DATABASE per tenant and MySQL-only generated columns, so MySQL or MariaDB is required — see docs/host-requirements.md.");

            return self::FAILURE;
        }

        // Both checks: the policy covers domain format, reserved words and
        // the domains table, but a tenant row can exist without one, and
        // CreateTenant would then adopt it and attach this owner to somebody
        // else's tenant.
        if (Numerosis::model(Tenant::class)::whereKey($slug)->exists()) {
            $this->error("A tenant with id [{$slug}] already exists.");

            return self::FAILURE;
        }

        // ValidationException, not ShowsMessageToUser: the policy contract
        // documents the former, and it is not one of the latter.
        try {
            $this->domainPolicy->assertAvailable($slug);
        } catch (ValidationException $e) {
            $this->error(implode(' ', $e->validator->errors()->all()));

            return self::FAILURE;
        }

        // Checked here rather than left to AddTenantOwner, which would fail
        // inside a queued job with nobody watching.
        $exists = Numerosis::model(CentralUser::class)::where('global_id', $owner)->exists();

        if (! $exists) {
            $this->error("No central user has global_id [{$owner}].");

            return self::FAILURE;
        }

        $customDomain = is_string($this->option('custom-domain')) ? $this->option('custom-domain') : '';

        if ($customDomain !== '') {
            try {
                $this->domainPolicy->assertCustomDomainAvailable($customDomain);
            } catch (ValidationException $e) {
                $this->error(implode(' ', $e->validator->errors()->all()));

                return self::FAILURE;
            }
        }

        $data = new TenantProvisionData(
            slug: $slug,
            name: is_string($this->option('name')) && $this->option('name') !== ''
                ? $this->option('name')
                : $slug,
            contributions: [
                new OwnerContribution($owner),
                ...($customDomain === '' ? [] : [new CustomDomainContribution($customDomain)]),
            ],
        );

        if ($this->option('sync')) {
            $this->provisioning->now($data);

            $this->info("Provisioned [{$slug}].");

            return self::SUCCESS;
        }

        $this->provisioning->queue($data);

        $this->info("Queued provisioning for [{$slug}] on the `provisioning` queue.");
        $this->line('  php artisan queue:work --queue=provisioning');
        $this->line('  ...or pass --sync to run the steps here.');

        return self::SUCCESS;
    }
}

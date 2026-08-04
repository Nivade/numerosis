<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\TenantProvisionStatus;
use Nvade\Numerosis\Exceptions\Tenancy\DomainAlreadyClaimed;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Lorisleiva\Actions\Concerns\AsAction;

class ReserveTenantDomain
{
    use AsAction;

    public function __construct(private readonly TenantDomainPolicy $domainPolicy) {}

    /**
     * See .claude/rules/tenant-provisioning.md.
     *
     * @throws DomainAlreadyClaimed
     */
    public function handle(TenantRegistrationData $registration): void
    {
        $this->domainPolicy->assertAvailable($registration->domain);

        $reservation = PendingTenantProvision::firstOrCreate(
            ['domain' => $registration->domain],
            [
                'company_name' => $registration->company_name,
                'global_id' => $registration->global_id,
                'status' => TenantProvisionStatus::Reserved,
            ],
        );

        if ($reservation->global_id !== $registration->global_id) {
            throw new DomainAlreadyClaimed("The domain {$registration->domain} is already being claimed.");
        }
    }
}

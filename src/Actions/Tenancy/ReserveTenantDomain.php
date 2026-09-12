<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Exceptions\Tenancy\DomainAlreadyClaimed;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;

/**
 * Claims a domain for a user before they are sent to Stripe, so two people
 * cannot pay for the same one. Re-running it for the same user is harmless.
 */
class ReserveTenantDomain
{
    use AsAction;

    public function __construct(private readonly TenantDomainPolicy $domainPolicy) {}

    /**
     * @throws DomainAlreadyClaimed
     */
    public function handle(TenantProvisionData $registration): void
    {
        $this->domainPolicy->assertAvailable($registration->slug);

        if ($registration->custom_domain !== null) {
            $this->domainPolicy->assertCustomDomainAvailable($registration->custom_domain);
        }

        /** @var TenantProvision $reservation */
        $reservation = Numerosis::model(TenantProvision::class)::firstOrCreate(
            ['slug' => $registration->slug],
            [
                'custom_domain' => $registration->custom_domain,
                'name' => $registration->name,
                'global_id' => $registration->global_id,
                'status' => TenantProvisionStatus::Reserved,
            ],
        );

        if ($reservation->global_id !== $registration->global_id) {
            throw new DomainAlreadyClaimed("The domain {$registration->slug} is already being claimed.");
        }
    }
}

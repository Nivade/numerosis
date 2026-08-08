<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Testing;

use Illuminate\Support\Collection;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Billing\CheckoutIntent;
use Nvade\Numerosis\Data\Billing\Intents\RedirectCheckout;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Support\Routes\RouteNames;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Records checkouts and provisioning requests instead of touching Stripe or
 * queuing real tenant provisioning. Bound in place of both CheckoutGateway
 * and ProvisionsTenant by Billing::fake().
 */
class FakeCheckoutGateway implements CheckoutGateway, ProvisionsTenant
{
    /** @var Collection<int, TenantRegistrationData> */
    private readonly Collection $checkoutsStarted;

    /** @var Collection<int, TenantProvisionData> */
    private readonly Collection $provisioned;

    public function __construct()
    {
        $this->checkoutsStarted = new Collection;
        $this->provisioned = new Collection;
    }

    public function begin(TenantRegistrationData $registration): CheckoutIntent
    {
        $this->checkoutsStarted->push($registration);

        return new RedirectCheckout(route(RouteNames::tenantsMine()));
    }

    public function queue(TenantProvisionData $data): void
    {
        $this->provisioned->push($data);
    }

    public function assertCheckoutStarted(?string $domain = null): void
    {
        PHPUnit::assertTrue(
            $domain === null
                ? $this->checkoutsStarted->isNotEmpty()
                : $this->checkoutsStarted->contains(fn (TenantRegistrationData $r): bool => $r->domain === $domain),
            $domain === null
                ? 'No checkout was started.'
                : "No checkout was started for domain [{$domain}]."
        );
    }

    public function assertTenantProvisioned(?string $domain = null): void
    {
        PHPUnit::assertTrue(
            $domain === null
                ? $this->provisioned->isNotEmpty()
                : $this->provisioned->contains(fn (TenantProvisionData $d): bool => $d->registration->domain === $domain),
            $domain === null
                ? 'No tenant was provisioned.'
                : "No tenant was provisioned for domain [{$domain}]."
        );
    }
}

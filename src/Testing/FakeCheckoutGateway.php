<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Testing;

use Illuminate\Support\Collection;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Billing\CheckoutIntent;
use Nvade\Numerosis\Data\Billing\Intents\RedirectCheckout;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Routing\RouteNames;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Records checkouts and provisioning requests, touching neither Stripe nor
 * the queue. Bound in place of both CheckoutGateway and ProvisionsTenant by
 * Billing::fake().
 */
class FakeCheckoutGateway implements CheckoutGateway, ProvisionsTenant
{
    /**
     * Not `readonly`: that pins the property to the type of the expression
     * assigned in the constructor, and `new Collection` infers
     * `Collection<*NEVER*, *NEVER*>`, which makes every later `isNotEmpty()`
     * look statically impossible even though `push()` fills it at runtime.
     *
     * @var Collection<int, TenantProvisionData>
     */
    private Collection $checkoutsStarted;

    /** @var Collection<int, TenantProvisionData> */
    private Collection $provisioned;

    public function __construct()
    {
        $this->checkoutsStarted = new Collection;
        $this->provisioned = new Collection;
    }

    public function begin(TenantProvisionData $registration): CheckoutIntent
    {
        $this->checkoutsStarted->push($registration);

        return new RedirectCheckout(route(RouteNames::tenantsMine()));
    }

    public function queue(TenantProvisionData $data): void
    {
        $this->provisioned->push($data);
    }

    /** Recorded identically: the fake never runs a step either way. */
    public function now(TenantProvisionData $data): void
    {
        $this->provisioned->push($data);
    }

    public function assertCheckoutStarted(?string $domain = null): void
    {
        PHPUnit::assertTrue(
            $domain === null
                ? $this->checkoutsStarted->isNotEmpty()
                : $this->checkoutsStarted->contains(fn (TenantProvisionData $r): bool => $r->slug === $domain),
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
                : $this->provisioned->contains(fn (TenantProvisionData $d): bool => $d->slug === $domain),
            $domain === null
                ? 'No tenant was provisioned.'
                : "No tenant was provisioned for domain [{$domain}]."
        );
    }
}

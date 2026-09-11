<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing\Checkout;

use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Actions\Tenancy\MarkProvisionInProgress;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Billing\CheckoutIntent;
use Nvade\Numerosis\Data\Billing\Intents\RedirectCheckout;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Routing\RouteNames;

/**
 * Provisions a tenant without touching Stripe.
 *
 * Backs both the local-only dev checkout route and, as a bound alternative to
 * InlineCheckoutGateway, the seam test proving CheckoutGateway is swappable.
 */
class LocalCheckoutGateway implements CheckoutGateway
{
    public function __construct(private readonly ProvisionsTenant $provisioning) {}

    public function begin(TenantRegistrationData $registration): CheckoutIntent
    {
        MarkProvisionInProgress::run($registration);

        // Kept as two statements: PHPStan does not credit `?->` with
        // handling the null when it is chained straight onto an action's
        // `::run()`, and reports `Cannot access property $id on User|null`.
        $user = GetAuthenticatedUser::run();
        $userId = $user?->id;

        $this->provisioning->queue(new TenantProvisionData(
            registration: $registration,
            centralUserId: $userId !== null ? (string) $userId : null,
        ));

        return new RedirectCheckout(route(RouteNames::tenantsMine()));
    }
}

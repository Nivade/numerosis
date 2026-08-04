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
use Nvade\Numerosis\Features\Ui\AccountPagesFeature;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Routes\RouteNames;

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

        $userId = GetAuthenticatedUser::run()?->id;

        $this->provisioning->queue(new TenantProvisionData(
            registration: $registration,
            centralUserId: $userId !== null ? (string) $userId : null,
        ));

        $route = Features::enabled(AccountPagesFeature::NAME) ? RouteNames::tenantsMine() : RouteNames::home();

        return new RedirectCheckout(route($route));
    }
}

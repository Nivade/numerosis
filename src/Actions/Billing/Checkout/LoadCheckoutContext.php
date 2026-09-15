<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Billing\FetchReusablePaymentMethods;
use Nvade\Numerosis\Actions\Billing\FetchSavedBillingDetails;
use Nvade\Numerosis\Actions\Billing\FetchStripeCustomer;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Contracts\Billing\CheckoutRegionResolver;
use Nvade\Numerosis\Data\Billing\Checkout\CheckoutContext;
use Nvade\Numerosis\Data\Billing\SavedBillingDetails;
use Nvade\Numerosis\Enums\Billing\PaymentMethodType;
use Nvade\Numerosis\Enums\FetchState;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;

/**
 * Everything the checkout screen loads before it can render: the region, the
 * payer, what Stripe already holds for them, and the checkout to pick back up.
 * Six lookups in a fixed order, so that its caller does not have to know one.
 *
 * @method static CheckoutContext run(string $domain, Request $request)
 */
class LoadCheckoutContext
{
    use AsAction;

    public function handle(string $domain, Request $request): CheckoutContext
    {
        $country = resolve(CheckoutRegionResolver::class)->resolve($request);

        $user = GetAuthenticatedUser::run();
        $billable = $user instanceof BillableUser ? $user : null;

        [$saved, $methods, $methodsFetchState] = $this->stripeDetails($billable);

        $resumed = null;
        $error = null;

        try {
            $resumed = ResumeCheckout::run($domain);
        } catch (ShowsMessageToUser $e) {
            $error = $e->getMessage();
        }

        return new CheckoutContext(
            detectedCountry: $country,
            paymentMethodOrder: $this->paymentMethodOrder($country),
            customerEmail: $billable?->email,
            vatNumber: $saved->vatNumber,
            savedBillingAddress: $this->address($saved),
            savedBillingFetchState: $saved->fetchState,
            savedPaymentMethods: $methods,
            savedPaymentMethodsFetchState: $methodsFetchState,
            resumed: $resumed,
            error: $error,
        );
    }

    /**
     * @return array{SavedBillingDetails, array<int, array<string, mixed>>, FetchState}
     */
    private function stripeDetails(?BillableUser $billable): array
    {
        if (! $billable instanceof BillableUser || ! $billable->hasStripeId()) {
            return [new SavedBillingDetails, [], FetchState::NotAttempted];
        }

        // One retrieve for both readers below. They each did their own,
        // serially, which cost the page a second Stripe round trip for the
        // same customer.
        $customer = FetchStripeCustomer::run($billable);

        if ($customer === null) {
            return [new SavedBillingDetails(fetchState: FetchState::Failed), [], FetchState::Failed];
        }

        $saved = FetchSavedBillingDetails::run($billable, $customer);
        $methods = FetchReusablePaymentMethods::run($billable, $customer);

        return [$saved, $methods->options->map->toArray()->all(), $methods->fetchState];
    }

    /**
     * @return array{name: ?string, address: array{line1: ?string, line2: ?string, city: ?string, state: ?string, postal_code: ?string, country: ?string}}|null
     */
    private function address(SavedBillingDetails $saved): ?array
    {
        if ($saved->fetchState === FetchState::Failed || ! $saved->hasAddress()) {
            return null;
        }

        return [
            'name' => $saved->name,
            'address' => [
                'line1' => $saved->line1,
                'line2' => $saved->line2,
                'city' => $saved->city,
                'state' => $saved->state,
                'postal_code' => $saved->postalCode,
                'country' => $saved->country,
            ],
        ];
    }

    /**
     * The curated payment method display order for a resolved country, or the
     * config default when the country is null (unresolved) or has no curated
     * entry of its own. Ordering only, never eligibility.
     *
     * @return list<string>
     */
    private function paymentMethodOrder(?string $country): array
    {
        $default = Config::array('numerosis.billing.payment_methods.default_order');

        /** @var array<mixed> $order */
        $order = $country !== null
            ? Config::array("numerosis.billing.payment_methods.regions.{$country}", $default)
            : $default;

        return array_values(array_filter(
            $order,
            fn (mixed $type): bool => is_string($type) && PaymentMethodType::tryFrom($type) !== null,
        ));
    }
}

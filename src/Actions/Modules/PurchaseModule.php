<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Modules;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use InterNACHI\Modular\Support\Facades\Modules;
use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Modules\Concerns\GuardsModuleBilling;
use Nvade\Numerosis\Contracts\Billing\ModuleCatalog;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Enums\ModuleBillingMode;
use Nvade\Numerosis\Events\Modules\ModulePurchased;
use Nvade\Numerosis\Exceptions\Billing\BillingAddressRequired;
use Nvade\Numerosis\Exceptions\Billing\BillingAddressUnavailable;
use Nvade\Numerosis\Exceptions\Billing\ModuleAlreadyPurchased;
use Nvade\Numerosis\Exceptions\Billing\ModuleBillingNotAuthorized;
use Nvade\Numerosis\Exceptions\Billing\ModuleNotFound;
use Nvade\Numerosis\Exceptions\Billing\ModuleNotInstalled;
use Nvade\Numerosis\Exceptions\Billing\StripePriceNotConfigured;
use Nvade\Numerosis\Exceptions\Billing\SubscriptionRequired;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Support\Numerosis;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * See .claude/rules/module-marketplace.md.
 *
 * @method static void run(Tenant $tenant, CentralUser|TenantUser $actor, string $slug)
 */
class PurchaseModule
{
    use AsAction;
    use GuardsModuleBilling;

    public function __construct(private readonly ModuleCatalog $catalog) {}

    public function handle(Tenant $tenant, CentralUser|TenantUser $actor, string $slug): void
    {
        Cache::lock("module-purchase:{$tenant->getTenantKey()}:{$slug}", 10)->block(5, function () use ($tenant, $actor, $slug): void {
            $this->purchase($tenant, $actor, $slug);
        });
    }

    private function purchase(Tenant $tenant, CentralUser|TenantUser $actor, string $slug): void
    {
        $offer = $this->catalog->findBySlug($slug);

        throw_unless($offer, ModuleNotFound::class, "Module not found: {$slug}");

        $this->assertModulesAvailable();
        $this->assertRunningInsideTenant($tenant);

        if (! Gate::forUser($actor)->allows('purchase', Module::class)) {
            throw new ModuleBillingNotAuthorized(__('numerosis::billing.modules.purchase_not_authorized'));
        }

        throw_unless(Modules::module($slug), ModuleNotInstalled::class, "Module not installed: {$slug}");

        $moduleClass = Numerosis::model(Module::class);

        throw_if($moduleClass::where('name', $slug)->whereNotNull('purchased_at')->exists(), ModuleAlreadyPurchased::class, "Module already purchased: {$slug}");

        throw_unless($this->hasBillingAddress($tenant), BillingAddressRequired::class, 'A billing address is required before purchasing a module.');

        $subscriptionItemId = null;
        $billingCycle = null;

        if ($offer->billingMode() === ModuleBillingMode::Recurring) {
            $subscription = $tenant->latestSubscription();

            throw_if(! $subscription || ! $subscription->active(), SubscriptionRequired::class, 'An active subscription is required to add this module.');

            $billingCycle = $this->billingCycleFor($subscription);
            $priceId = $offer->priceId($billingCycle);

            throw_if($priceId === null, StripePriceNotConfigured::class, "Stripe price not configured for module: {$slug}");

            if ($subscription->onTrial()) {
                $subscription->addPrice($priceId);
            } else {
                $subscription->addPriceAndInvoice($priceId);
            }

            $createdItemId = $subscription->items()->where('stripe_price', $priceId)->value('stripe_id');
            $subscriptionItemId = is_string($createdItemId) ? $createdItemId : null;
        } else {
            $priceId = $offer->priceId(null);

            throw_if($priceId === null, StripePriceNotConfigured::class, "Stripe price not configured for module: {$slug}");

            $tenant->invoicePrice($priceId, 1);
        }

        try {
            RecordModulePurchase::run($offer, $subscriptionItemId, $billingCycle);
        } catch (Throwable $e) {
            // See .claude/rules/module-marketplace.md — known gap, not fixed.
            report($e);

            throw $e;
        }

        event(new ModulePurchased($tenant, $slug));
    }

    private function hasBillingAddress(Tenant $tenant): bool
    {
        $stripeId = $tenant->stripe_id;

        if ($stripeId === null) {
            return false;
        }

        try {
            $customer = Cashier::stripe()->customers->retrieve($stripeId);
        } catch (ApiErrorException $e) {
            report($e);

            throw new BillingAddressUnavailable('Unable to verify your billing address right now. Please try again shortly.', $e->getCode(), $e);
        }

        $address = $customer->address;

        if (! is_object($address)) {
            return false;
        }

        return filled($address->line1 ?? null) && filled($address->country ?? null);
    }

    private function billingCycleFor(Subscription $subscription): BillingCycle
    {
        $plan = $subscription->paymentPlan;

        if ($plan && $subscription->stripe_price === $plan->getPriceId(BillingCycle::Yearly)) {
            return BillingCycle::Yearly;
        }

        return BillingCycle::Monthly;
    }
}

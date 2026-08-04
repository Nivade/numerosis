<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Providers;

use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Listeners\Billing\SyncTenantToStripeOnSave;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Stancl\Tenancy\Events\TenantSaved;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/numerosis-billing.php', 'numerosis-billing');

        /** @var array<class-string, class-string> $implementations */
        $implementations = config('numerosis-billing.implementations', []);

        foreach ($implementations as $contract => $concrete) {
            $this->app->bind($contract, $concrete);
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/numerosis-billing.php' => config_path('numerosis-billing.php'),
        ], 'billing-config');

        /** @var class-string<Model> $tenantModel */
        $tenantModel = config('numerosis-billing.models.tenant');
        /** @var class-string<Model> $subscriptionModel */
        $subscriptionModel = config('numerosis-billing.models.subscription');
        /** @var class-string<Model> $subscriptionItemModel */
        $subscriptionItemModel = config('numerosis-billing.models.subscription_item');

        Cashier::useCustomerModel($tenantModel);
        Cashier::useSubscriptionModel($subscriptionModel);
        Cashier::useSubscriptionItemModel($subscriptionItemModel);
        Cashier::calculateTaxes();

        if (config('numerosis-billing.sync.stripe_customer', true)) {
            $this->configureStripeSync();
        }

        AboutCommand::add('Billing', fn (): array => [
            'Checkout gateway' => config('numerosis-billing.implementations.'.CheckoutGateway::class),
            'Plan source' => config('numerosis-billing.implementations.'.PaymentPlanRepository::class),
            'Billable model' => config('numerosis-billing.implementations.'.BillableResolver::class),
        ]);
    }

    /**
     * Configure Stripe customer data synchronization.
     */
    protected function configureStripeSync(): void
    {
        $this->app->make(Dispatcher::class)->listen(TenantSaved::class, [SyncTenantToStripeOnSave::class, 'sync']);
    }
}

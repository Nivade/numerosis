<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Contracts\Billing\Entitlements;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Listeners\Billing\SyncTenantToStripeOnSave;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Services\Billing\PlanEntitlements;
use Override;
use Stancl\Tenancy\Events\TenantSaved;

class BillingServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        /** @var array<class-string, class-string> $implementations */
        $implementations = config('numerosis.billing.implementations', []);

        foreach ($implementations as $contract => $concrete) {
            $this->app->bind($contract, $concrete);
        }

        // A singleton, unlike the rest: its per-request memo of resolved
        // entitlements is the point, and a fresh instance per resolve would
        // re-read the plan on every check.
        $this->app->singleton(function (Application $app) use ($implementations): Entitlements {
            /** @var class-string<Entitlements> $concrete */
            $concrete = $implementations[Entitlements::class] ?? PlanEntitlements::class;

            return $app->make($concrete);
        });
    }

    public function boot(): void
    {
        Cashier::useCustomerModel($this->billableModel('tenant'));
        Cashier::useSubscriptionModel($this->billableModel('subscription'));
        Cashier::useSubscriptionItemModel($this->billableModel('subscription_item'));
        Cashier::calculateTaxes();

        if (Config::boolean('numerosis.billing.sync.stripe_customer', true)) {
            $this->configureStripeSync();
        }

        AboutCommand::add('Billing', fn (): array => [
            'Checkout gateway' => config('numerosis.billing.implementations.'.CheckoutGateway::class),
            'Plan source' => config('numerosis.billing.implementations.'.PaymentPlanRepository::class),
            'Billable model' => config('numerosis.billing.implementations.'.BillableResolver::class),
        ]);
    }

    /**
     * `numerosis.billing.models.*` default to this package's own abstract model
     * classes, so they must resolve to a concrete one before Cashier is told
     * about them: `findBillable()` and `newSubscription()` do `new $model`, and
     * an abstract class there throws `Cannot instantiate abstract class` from
     * inside vendor code, far from this line.
     *
     * @return class-string<Model>
     */
    protected function billableModel(string $key): string
    {
        /** @var class-string<Model> $configured */
        $configured = Config::string("numerosis.billing.models.{$key}");

        return Numerosis::model($configured);
    }

    protected function configureStripeSync(): void
    {
        $this->app->make(Dispatcher::class)->listen(TenantSaved::class, [SyncTenantToStripeOnSave::class, 'sync']);
    }
}

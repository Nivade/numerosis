<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Listeners\Billing\SyncTenantToStripeOnSave;
use Nvade\Numerosis\Support\Numerosis;
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
    }

    public function boot(): void
    {
        Cashier::useCustomerModel($this->billableModel('tenant'));
        Cashier::useSubscriptionModel($this->billableModel('subscription'));
        Cashier::useSubscriptionItemModel($this->billableModel('subscription_item'));
        Cashier::calculateTaxes();

        if (config('numerosis.billing.sync.stripe_customer', true)) {
            $this->configureStripeSync();
        }

        AboutCommand::add('Billing', fn (): array => [
            'Checkout gateway' => config('numerosis.billing.implementations.'.CheckoutGateway::class),
            'Plan source' => config('numerosis.billing.implementations.'.PaymentPlanRepository::class),
            'Billable model' => config('numerosis.billing.implementations.'.BillableResolver::class),
        ]);
    }

    /**
     * `numerosis.billing.models.*` default to this package's own *abstract*
     * model classes, so they must be resolved to the host's concrete stubs
     * before Cashier is told about them: Cashier's `findBillable()` and
     * `newSubscription()` do `new $model`, so an abstract class here throws
     * `Cannot instantiate abstract class` far away from this line, from inside
     * vendor code. A host that has already pointed these keys at a concrete
     * class of its own passes through `Numerosis::model()` unchanged.
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

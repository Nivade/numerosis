<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Modules;

use Illuminate\Support\Facades\Gate;
use Laravel\Cashier\SubscriptionItem;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Modules\Concerns\GuardsModuleBilling;
use Nvade\Numerosis\Contracts\Billing\ModuleCatalog;
use Nvade\Numerosis\Enums\Billing\ModuleBillingMode;
use Nvade\Numerosis\Exceptions\Billing\ModuleBillingNotAuthorized;
use Nvade\Numerosis\Exceptions\Billing\ModuleNotFound;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Support\Numerosis;

/**
 * Stops billing for a module and disables it, leaving its tenant data in
 * place. Dropping that data is a separate, explicit operation.
 *
 * Must run inside the tenant it is cancelling for.
 *
 * @method static void run(Tenant $tenant, CentralUser|TenantUser $actor, string $slug)
 */
class CancelModule
{
    use AsAction;
    use GuardsModuleBilling;

    public function __construct(private readonly ModuleCatalog $catalog) {}

    public function handle(Tenant $tenant, CentralUser|TenantUser $actor, string $slug): void
    {
        $this->assertRunningInsideTenant($tenant);

        $moduleClass = Numerosis::model(Module::class);

        /** @var Module|null $module */
        $module = $moduleClass::where('name', $slug)->whereNotNull('purchased_at')->first();

        throw_unless($module, ModuleNotFound::class, "Module not purchased: {$slug}");

        if (! Gate::forUser($actor)->allows('cancel', $module)) {
            throw new ModuleBillingNotAuthorized(__('numerosis::billing.modules.cancel_not_authorized'));
        }

        $offer = $this->catalog->findAnyBySlug($slug);

        if ($offer && $offer->billingMode() === ModuleBillingMode::Recurring && $module->stripe_subscription_item_id) {
            $subscription = $tenant->latestSubscription();

            /** @var SubscriptionItem|null $item */
            $item = $subscription?->items()->where('stripe_id', $module->stripe_subscription_item_id)->first();

            if ($subscription && $item) {
                $subscription->removePrice($item->stripe_price);
            }
        }

        $module->disable();
    }
}

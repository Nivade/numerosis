<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Modules;

use Illuminate\Database\QueryException;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Support\Numerosis;
use PDOException;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * Disables modules whose Stripe subscription item is gone, from a
 * subscription-updated webhook.
 *
 * Only ever disables. Re-adding a price in Stripe's portal does not
 * re-enable a module — purchasing is the only path that does.
 */
class ReconcileModuleSubscriptionItems
{
    use AsAction;

    /**
     * @param  array{items?: array{data?: list<array{id?: string}>}}  $stripeSubscription
     */
    public function handle(Tenant $tenant, array $stripeSubscription): void
    {
        $currentItemIds = array_filter(array_column($stripeSubscription['items']['data'] ?? [], 'id'));

        if ($currentItemIds === []) {
            return;
        }

        // Tenancy is entered and reverted by hand rather than through
        // $tenant->run(), which offers no guarantee of reverting if the
        // callback throws — likely here, since a webhook can arrive before
        // the tenant database exists.
        /** @var TenantContract|null $originalTenant */
        $originalTenant = tenant();

        try {
            tenancy()->initialize($tenant);

            $moduleClass = Numerosis::model(Module::class);

            $moduleClass::query()
                ->where('enabled', true)
                ->whereNotNull('stripe_subscription_item_id')
                ->whereNotIn('stripe_subscription_item_id', $currentItemIds)
                ->get()
                ->each(function ($module): void {
                    /** @var Module $module */
                    $module->disable();
                });
        } catch (QueryException|PDOException $e) {
            report($e);
        } finally {
            if ($originalTenant) {
                tenancy()->initialize($originalTenant);
            } else {
                tenancy()->end();
            }
        }
    }
}

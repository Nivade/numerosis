<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Modules;

use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Module;
use Illuminate\Database\QueryException;
use Lorisleiva\Actions\Concerns\AsAction;
use PDOException;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

// See .claude/rules/module-marketplace.md.
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

        // Not $tenant->run() — see .claude/rules/module-marketplace.md.
        /** @var TenantContract|null $originalTenant */
        $originalTenant = tenant();

        try {
            tenancy()->initialize($tenant);

            Module::query()
                ->where('enabled', true)
                ->whereNotNull('stripe_subscription_item_id')
                ->whereNotIn('stripe_subscription_item_id', $currentItemIds)
                ->get()
                ->each(fn (Module $module) => $module->disable());
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

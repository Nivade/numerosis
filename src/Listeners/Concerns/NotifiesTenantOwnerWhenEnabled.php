<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Concerns;

use Illuminate\Notifications\Notification;
use Nvade\Numerosis\Contracts\Notifications\NotifiesTenantOwner;
use Nvade\Numerosis\Features\Billing\BillingNotificationsFeature;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\FeatureRegistry;

/**
 * `BillingNotificationsFeature` is read here, at call time, because the
 * listeners it gates are registered unconditionally by
 * `NumerosisServiceProvider::registerEventListeners()`.
 */
trait NotifiesTenantOwnerWhenEnabled
{
    protected function notifyTenantOwner(Tenant $tenant, Notification $notification): void
    {
        if (! FeatureRegistry::enabled(BillingNotificationsFeature::NAME)) {
            return;
        }

        resolve(NotifiesTenantOwner::class)->notify($tenant, $notification);
    }
}

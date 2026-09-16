<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Tenancy;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Queries\GetSystemHealth;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Contracts\Notifications\OperatorRecipient;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningFailed;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Notifications\Tenancy\ProvisioningFailed;
use Nvade\Numerosis\Numerosis;

class SendProvisioningFailedAlert
{
    public function handle(TenantProvisioningFailed $event): void
    {
        if (! $this->claimTheWindow()) {
            return;
        }

        $provision = Numerosis::model(TenantProvision::class)::find($event->domain);

        resolve(OperatorRecipient::class)->notify(new ProvisioningFailed(
            $event->domain,
            $provision instanceof TenantProvision ? $provision->failedStep() : null,
            $provision instanceof TenantProvision ? $provision->error : null,
            GetSystemHealth::run()->provisioning->failedLastHour,
        ));
    }

    /**
     * One alert per window, whichever failure gets there first. A bad deploy
     * fails every provision it touches, and two hundred mails describing the
     * same cause are worse than one.
     */
    private function claimTheWindow(): bool
    {
        $minutes = Config::integer('numerosis.notifications.throttle_minutes', 15);

        if ($minutes <= 0) {
            return true;
        }

        return GlobalCache::store()->add(CacheKeys::provisioningAlertThrottle(), true, $minutes * 60);
    }
}

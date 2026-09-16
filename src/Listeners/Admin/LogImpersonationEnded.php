<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Admin;

use Nvade\Numerosis\Actions\Queries\FindUserByGlobalId;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Events\Admin\ImpersonationEnded;

class LogImpersonationEnded
{
    public function handle(ImpersonationEnded $event): void
    {
        $staff = FindUserByGlobalId::run($event->staffGlobalId, Context::Central);

        activity()
            ->causedBy($staff)
            ->withProperties([
                'tenant_id' => $event->tenantId,
                'impersonation_session_id' => $event->sessionId,
                'target_global_id' => $event->targetGlobalId,
                'reason' => $event->reason->value,
            ])
            ->log("Impersonation of {$event->targetGlobalId} in {$event->tenantId} ended ({$event->reason->value})");
    }
}

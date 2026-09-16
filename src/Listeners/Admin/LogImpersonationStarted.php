<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Admin;

use Nvade\Numerosis\Actions\Queries\FindUserByGlobalId;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Events\Admin\ImpersonationStarted;

class LogImpersonationStarted
{
    public function handle(ImpersonationStarted $event): void
    {
        $staff = FindUserByGlobalId::run($event->staffGlobalId, Context::Central);

        activity()
            ->causedBy($staff)
            ->withProperties([
                'tenant_id' => $event->tenantId,
                'impersonation_session_id' => $event->sessionId,
                'target_global_id' => $event->targetGlobalId,
            ])
            ->log("Impersonation of {$event->targetGlobalId} in {$event->tenantId} started by staff");
    }
}

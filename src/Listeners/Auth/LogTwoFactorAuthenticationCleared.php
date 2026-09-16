<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Auth;

use Nvade\Numerosis\Actions\Queries\FindUserByGlobalId;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Events\Auth\TwoFactorAuthenticationCleared;

class LogTwoFactorAuthenticationCleared
{
    public function handle(TwoFactorAuthenticationCleared $event): void
    {
        $staff = FindUserByGlobalId::run($event->staffGlobalId, Context::Central);

        activity()
            ->causedBy($staff)
            ->withProperties([
                'staff_global_id' => $event->staffGlobalId,
                'target_global_id' => $event->targetGlobalId,
            ])
            ->log("Two-factor authentication for {$event->targetGlobalId} cleared by staff {$event->staffGlobalId}");
    }
}

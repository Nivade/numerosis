<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Admin;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Queries\GetCurrentImpersonation;
use Nvade\Numerosis\Models\Central\ImpersonationSession;

/**
 * Drops outbound mail while staff are signed in as a customer. Returning false
 * from either event cancels the send, so a support action that would ordinarily
 * mail the account holder does not.
 *
 * Toggle with `numerosis.tenancy.impersonation.suppress_mail`.
 */
class SuppressMailWhileImpersonating
{
    public function handle(MessageSending|NotificationSending $event): ?bool
    {
        if (! Config::boolean('numerosis.tenancy.impersonation.suppress_mail', true)) {
            return null;
        }

        // `null`, never `true`: `Dispatcher::until()` returns the first
        // non-null response, so answering `true` would stop every other
        // listener on these two events from running at all.
        return GetCurrentImpersonation::run() instanceof ImpersonationSession ? false : null;
    }
}

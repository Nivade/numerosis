<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Admin;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\Session;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Concerns\Auth\ForgetsGuardSession;
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Enums\Tenancy\ImpersonationEndReason;
use Nvade\Numerosis\Events\Admin\ImpersonationEnded;
use Nvade\Numerosis\Models\Central\ImpersonationSession;

/**
 * Closes the audit row and drops the tenant guard's session, leaving the staff
 * user's own central session untouched so they land back on the panel without
 * signing in again.
 *
 * @method static void run(ImpersonationSession $session, ImpersonationEndReason $reason)
 */
class EndImpersonation
{
    use AsAction;
    use ForgetsGuardSession;

    public function __construct(private readonly AuthFactory $auth) {}

    public function handle(ImpersonationSession $session, ImpersonationEndReason $reason): void
    {
        $wasOpen = $session->isOpen();

        $session->close($reason);

        if (Session::get(SessionKey::ImpersonationSession->value) === $session->id) {
            // Never `logout()`: it resolves the tenant user first, and outside
            // tenancy that lookup runs against the central `users` table.
            $this->forgetGuardSession($this->auth->guard(Context::Tenant->guard()));

            Session::forget(SessionKey::ImpersonationSession->value);
        }

        if (! $wasOpen) {
            return;
        }

        event(new ImpersonationEnded(
            $session->id,
            $session->tenant_id,
            $session->staff_global_id,
            $session->target_global_id,
            $reason,
        ));
    }
}

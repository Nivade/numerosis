<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Nvade\Numerosis\Actions\Admin\EndImpersonation;
use Nvade\Numerosis\Actions\Queries\FindUserByGlobalId;
use Nvade\Numerosis\Actions\Queries\GetCurrentImpersonation;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Enums\Tenancy\ImpersonationEndReason;
use Nvade\Numerosis\Models\Central\ImpersonationSession;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Ends an impersonated session that has outrun
 * `numerosis.tenancy.impersonation.session_minutes`, before the request it
 * arrived on is handled. Nothing else expires it: the tenant guard's session
 * would otherwise last as long as the cookie.
 */
class GuardImpersonation
{
    public function handle(Request $request, Closure $next): mixed
    {
        $session = GetCurrentImpersonation::run();

        if ($session instanceof ImpersonationSession && $session->hasExpired()) {
            EndImpersonation::run($session, ImpersonationEndReason::Expired);

            $session = null;
        }

        if ($session instanceof ImpersonationSession) {
            $this->attributeWritesToStaff($session);
        }

        return $next($request);
    }

    /**
     * Every activity entry this request writes names the staff user, whatever
     * the guard holds. Without it a support action reads as the customer's
     * own, which is the one thing the audit trail exists to prevent.
     */
    private function attributeWritesToStaff(ImpersonationSession $session): void
    {
        $staff = FindUserByGlobalId::run($session->staff_global_id, Context::Central);

        if ($staff instanceof Model) {
            resolve(CauserResolver::class)->setCauser($staff);
        }
    }
}

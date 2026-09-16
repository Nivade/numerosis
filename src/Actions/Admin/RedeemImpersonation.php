<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Events\Admin\ImpersonationStarted;
use Nvade\Numerosis\Models\Central\ImpersonationSession;
use Stancl\Tenancy\Features\UserImpersonation;

/**
 * Spends the token on the tenant domain: stancl's own feature does the TTL
 * check, the login and the delete, and this stamps the audit row and marks the
 * session so the banner, the causer resolver and the expiry guard can see it.
 *
 * Runs after `EnsureSessionMatchesTenant`, which is what keeps the impersonated
 * session an ordinary tenant session rather than an exception to the rule.
 *
 * @method static RedirectResponse run(string $token)
 */
class RedeemImpersonation
{
    use AsAction;

    public function handle(string $token): RedirectResponse
    {
        $session = ImpersonationSession::query()
            ->where('token', $token)
            ->whereNull('started_at')
            ->firstOrFail();

        $response = UserImpersonation::makeResponse(
            $token,
            Config::integer('numerosis.tenancy.impersonation.token_seconds', 60),
        );

        $session->update(['started_at' => now()]);

        Session::put(SessionKey::ImpersonationSession->value, $session->id);

        event(new ImpersonationStarted(
            $session->id,
            $session->tenant_id,
            $session->staff_global_id,
            $session->target_global_id,
        ));

        return $response;
    }
}

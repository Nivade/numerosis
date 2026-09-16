<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Contracts\Auth\Factory;
use Nvade\Numerosis\Actions\Auth\LoginUser;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Actions\Queries\GetCurrentImpersonation;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\ImpersonationSession;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Override;

class Authenticate extends Middleware
{
    public function __construct(
        Factory $auth,
        private readonly AuthManager $authManager,
    ) {
        parent::__construct($auth);
    }

    /**
     * @param  string  ...$guards
     *
     * @throws AuthenticationException
     */
    #[Override]
    public function handle($request, Closure $next, ...$guards): mixed
    {
        $this->authenticate($request, array_values($guards));

        return $next($request);
    }

    /**
     * Authenticates the request, first promoting a central session into the
     * tenant guard where the central user may access the current tenant.
     *
     * @param  array<array-key, mixed>  $guards
     *
     * @throws AuthenticationException
     */
    #[Override]
    public function authenticate($request, array $guards): void
    {
        $centralGuard = Context::Central->guard();

        // Promotion would sign the staff user back in as themselves and end
        // the impersonation silently, on any tenant they happen to belong to.
        $impersonating = GetCurrentImpersonation::run() instanceof ImpersonationSession;

        if (! $impersonating && tenancy()->initialized && $this->authManager->guard($centralGuard)->check()) {
            /** @var CentralUser $centralUser */
            $centralUser = GetAuthenticatedUser::run($centralGuard);

            $currentTenant = tenant();

            if ($currentTenant instanceof Tenant && $centralUser->canAccessTenant($currentTenant)) {
                $tenantGuard = Context::Tenant->guard();

                $tenantUser = $this->authManager->guard($tenantGuard)->user();

                if (! $tenantUser instanceof TenantUser || $tenantUser->global_id !== $centralUser->global_id || $tenantUser->is_bot) {
                    LoginUser::run(user: $centralUser, guard: $tenantGuard);
                }
            }
        }

        if (! $this->auth->guard($this->authManager->getDefaultDriver())->check()) {
            $this->unauthenticated($request, $guards);
        }
    }

    /** @param  array<array-key, mixed>  $guards */
    protected function unauthenticated($request, array $guards): void
    {
        throw new AuthenticationException(
            'Unauthenticated.',
            $guards,
            $request->expectsJson() ? null : $this->redirectTo($request),
        );
    }
}

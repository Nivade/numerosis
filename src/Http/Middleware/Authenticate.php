<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Filament\Exceptions\NoDefaultPanelSetException;
use Filament\Facades\Filament;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Config\Repository;
use Nvade\Numerosis\Actions\Auth\LoginUser;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Actions\Queries\IsUserAuthenticated;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User;
use Override;

class Authenticate extends Middleware
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        Factory $auth,
        private readonly AuthManager $authManager,
        private readonly Repository $repository
    ) {
        parent::__construct($auth);
    }

    /**
     * Handle an incoming request.
     *
     *
     * @param  string  ...$guards
     *
     * @throws AuthenticationException|NoDefaultPanelSetException
     */
    #[Override]
    public function handle($request, Closure $next, ...$guards): mixed
    {
        $this->authenticate($request, array_values($guards));

        return $next($request);
    }

    /**
     * @param  array<int, string|null>  $guards
     *
     * @throws NoDefaultPanelSetException
     * @throws AuthenticationException
     */
    #[Override]
    public function authenticate($request, array $guards): void
    {
        // Are we on a tenant and is the current user authenticated on central?
        if (tenancy()->initialized && IsUserAuthenticated::run(Context::Central->guard())) {
            /** @var CentralUser $centralUser */
            $centralUser = GetAuthenticatedUser::run(Context::Central->guard());

            $currentTenant = tenant();

            if ($currentTenant instanceof Tenant && $centralUser->canAccessTenant($currentTenant)) {
                /** @var User $tenantUser */
                $tenantUser = IsUserAuthenticated::run(Context::Tenant->guard())
                    ? $this->authManager->guard(Context::Tenant->guard())->user()
                    : null;

                if (! $tenantUser || $tenantUser->global_id !== $centralUser->global_id || $tenantUser->is_bot) {
                    LoginUser::run(user: $centralUser, guard: Context::Tenant->guard());
                }
            }
        }

        if (! $this->auth->guard($this->authManager->getDefaultDriver())->check()) {
            $this->unauthenticated($request, $guards);
        }

        /** @var User $user */
        $user = $request->user();

        $panel = Filament::getCurrentOrDefaultPanel();

        abort_if(
            $panel !== null
                ?
                (! $user->canAccessPanel($panel))
                :
                ($this->repository->get('app.env') !== 'local'),
            403,
        );
    }

    #[Override]
    protected function redirectTo($request): ?string
    {
        return Filament::getLoginUrl();
    }

    /**
     * @param  array<int, string|null>  $guards
     */
    protected function unauthenticated($request, array $guards): void
    {
        throw new AuthenticationException(
            'Unauthenticated.',
            $guards,
            $request->expectsJson() ? null : $this->redirectTo($request),
        );
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Queries\FindUserByGlobalId;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\User;
use RuntimeException;

/**
 * @method static void run(User $user, bool $remember = false, ?string $guard = null)
 */
class LoginUser
{
    use AsAction;

    public function handle(User $user, bool $remember = false, ?string $guard = null): void
    {
        $guardName = $guard ?? Auth::getDefaultDriver();

        $this->loginToGuard($guardName, $user, $remember);

        $centralGuard = Config::string('numerosis.auth.guards.central', 'web');

        if ($guardName !== $centralGuard) {
            $this->loginToGuard($centralGuard, $user, $remember);
        }

        Session::regenerate();
    }

    protected function loginToGuard(string $guardName, User $user, bool $remember): void
    {
        Auth::guard($guardName)->login($this->resolveUserForGuard($guardName, $user), $remember);
    }

    protected function resolveUserForGuard(string $guardName, User $user): User
    {
        $guardInstance = Auth::guard($guardName);

        // @phpstan-ignore method.notFound
        $provider = $guardInstance->getProvider();

        throw_if($provider === null, RuntimeException::class, "No provider found for guard: {$guardName}");

        $expectedModel = $provider->getModel();

        return ($user instanceof $expectedModel)
            ? $user
            : $this->userResolver(global_id: $user->global_id, context: Context::fromGuard($guardName));
    }

    /**
     * Resolves the user model for a guard.
     *
     * The guard decides which model, never the ambient tenancy state: logging
     * into the central guard happens while tenant context is still active,
     * and resolving by context there would hand a tenant model to the central
     * guard.
     */
    protected function userResolver(string $global_id, Context $context): User
    {
        $user = FindUserByGlobalId::run($global_id, $context);

        throw_unless($user instanceof User, RuntimeException::class, "No user found for global_id: {$global_id}");

        return $user;
    }
}

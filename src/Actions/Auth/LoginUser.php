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

        $centralGuard = Config::string('auth.defaults.guards.context.central', 'web');

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
     * The guard being logged into decides which model to resolve — never the
     * ambient `tenancy()->initialized` state. The second `loginToGuard()` call
     * in `handle()` runs while tenant context is still active, so defaulting
     * to ambient context here re-resolved a second `Tenant\User` and logged it
     * into the *central* guard: `EloquentUserProvider::updateRememberToken()` then
     * saved that tenant model through the central guard's machinery, and the
     * queued `SyncedResourceSaved` listener threw `ModelNotSyncMasterException`
     * (a `Tenant\User` is never a `SyncMaster`) — see
     * .claude/rules/auth-guards.md.
     */
    protected function userResolver(string $global_id, Context $context): User
    {
        $user = FindUserByGlobalId::run($global_id, $context);

        throw_unless($user instanceof User, RuntimeException::class, "No user found for global_id: {$global_id}");

        return $user;
    }
}

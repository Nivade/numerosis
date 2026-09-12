<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Facades\Auth;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\User;

/**
 * @method static ?User run(?string $guard = null)
 */
class GetAuthenticatedUser
{
    use AsAction;

    /**
     * Resolve the authenticated user, defaulting to whichever guard matches
     * the current context.
     *
     * @see \Nvade\Numerosis\Services\Tenancy\Bootstrappers\AuthGuardBootstrapper
     */
    public function handle(?string $guard = null): ?User
    {
        return Auth::guard($guard)->user();
    }
}

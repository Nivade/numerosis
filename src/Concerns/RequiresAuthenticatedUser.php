<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns;

use Nvade\Numerosis\Contracts\Auth\CentralUserModel;
use Nvade\Numerosis\Contracts\Auth\TenantUserModel;
use Nvade\Numerosis\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

trait RequiresAuthenticatedUser
{
    /**
     * Resolve the authenticated user, failing loudly instead of silently
     * operating on null when the guard has no user.
     *
     * @throws AuthenticationException
     */
    protected function authenticatedUser(): (User&CentralUserModel)|(User&TenantUserModel)
    {
        $user = Auth::user();

        throw_if(! $user instanceof CentralUserModel && ! $user instanceof TenantUserModel, AuthenticationException::class);

        /** @var (User&CentralUserModel)|(User&TenantUserModel) $user */
        return $user;
    }
}

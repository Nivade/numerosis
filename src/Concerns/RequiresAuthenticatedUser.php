<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns;

use Illuminate\Auth\AuthenticationException;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Contracts\Auth\CentralUserModel;
use Nvade\Numerosis\Contracts\Auth\TenantUserModel;
use Nvade\Numerosis\Models\User;

trait RequiresAuthenticatedUser
{
    /**
     * Resolve the authenticated user, failing loudly when the guard has no
     * user so nothing silently operates on null.
     *
     * @throws AuthenticationException
     */
    protected function authenticatedUser(): (User&CentralUserModel)|(User&TenantUserModel)
    {
        $user = GetAuthenticatedUser::run();

        throw_if(! $user instanceof CentralUserModel && ! $user instanceof TenantUserModel, AuthenticationException::class);

        /** @var (User&CentralUserModel)|(User&TenantUserModel) $user */
        return $user;
    }
}

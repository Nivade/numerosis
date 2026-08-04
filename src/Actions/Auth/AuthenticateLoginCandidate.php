<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Nvade\Numerosis\Contracts\Auth\AuthenticatesLoginCandidate;
use Nvade\Numerosis\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * @method static void run(Authenticatable $user, bool $remember = false)
 */
class AuthenticateLoginCandidate implements AuthenticatesLoginCandidate
{
    use AsAction;

    public function handle(Authenticatable $user, bool $remember = false): void
    {
        $this->authenticate($user, $remember);
    }

    public function authenticate(Authenticatable $user, bool $remember): void
    {
        throw_unless($user instanceof User, RuntimeException::class, 'Expected an Nvade\Numerosis\Models\User instance.');

        LoginUser::run($user, $remember);
    }
}

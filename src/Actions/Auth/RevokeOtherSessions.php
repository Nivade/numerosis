<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Contracts\Session\Session;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Auth\SessionRegistry;

/**
 * Deletes every stored session of one user but the one making the request.
 * On a driver the registry cannot list this does nothing, which is why the
 * password stamp — `AuthenticateSession` plus `logoutOtherDevices()` — is the
 * other half of revocation rather than a refinement of this one.
 */
class RevokeOtherSessions
{
    use AsAction;

    public function __construct(
        private readonly SessionRegistry $registry,
        private readonly Session $session,
    ) {}

    /** @return int<0, max> */
    public function handle(string $guard, int|string $userId, bool $keepCurrent = true): int
    {
        return $this->registry->forgetOthers(
            $guard,
            $userId,
            $keepCurrent ? $this->session->getId() : null,
        );
    }
}

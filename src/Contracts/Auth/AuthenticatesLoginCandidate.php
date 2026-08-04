<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Performs the actual login once a candidate has passed rate-limiting and
 * one-time-password verification. Bind a replacement in a service provider to
 * add side effects (audit logging, welcome email) or change how the session
 * is established, without touching the verification steps in the component.
 */
interface AuthenticatesLoginCandidate
{
    public function authenticate(Authenticatable $user, bool $remember): void;
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves the user a submitted login email refers to. Bind a replacement in
 * a service provider to change how the login flow looks users up (e.g. an
 * SSO-backed directory) without forking the login component.
 */
interface ResolvesLoginCandidate
{
    public function find(string $email): ?Authenticatable;
}

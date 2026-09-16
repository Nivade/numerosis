<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Auth;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by both password write paths, the settings screen's and the
 * reset link's. Carries the guard the id belongs to: a tenant user's primary
 * key means nothing against the central `users` table.
 */
class PasswordChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $guard,
        public readonly int|string $userId,
    ) {}
}

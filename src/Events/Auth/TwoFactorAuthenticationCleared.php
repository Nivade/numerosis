<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Auth;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A staff user cleared someone else's second factor.
 */
class TwoFactorAuthenticationCleared implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $staffGlobalId,
        public readonly string $targetGlobalId,
    ) {}
}

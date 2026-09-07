<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Auth;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Scalars only. The row no longer exists by the time this fires.
 */
class UserAccountDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $globalId,
        public readonly ?string $email,
    ) {}
}

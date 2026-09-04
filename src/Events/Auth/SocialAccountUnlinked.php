<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Auth;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Scalars only — the row is gone by the time a queued listener runs.
 */
class SocialAccountUnlinked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $globalUserId,
        public readonly string $provider,
    ) {}
}

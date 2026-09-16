<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Admin;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ImpersonationStarted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $sessionId,
        public readonly string $tenantId,
        public readonly string $staffGlobalId,
        public readonly string $targetGlobalId,
    ) {}
}

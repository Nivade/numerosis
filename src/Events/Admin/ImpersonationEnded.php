<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Admin;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nvade\Numerosis\Enums\Tenancy\ImpersonationEndReason;

class ImpersonationEnded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $sessionId,
        public readonly string $tenantId,
        public readonly string $staffGlobalId,
        public readonly string $targetGlobalId,
        public readonly ImpersonationEndReason $reason,
    ) {}
}

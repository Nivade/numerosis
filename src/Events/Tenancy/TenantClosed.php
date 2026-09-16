<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Tenancy;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

class TenantClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $ownerGlobalId,
        public readonly Carbon $closedAt,
    ) {}
}

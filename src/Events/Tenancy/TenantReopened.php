<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Tenancy;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TenantReopened implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $ownerGlobalId,
        public readonly bool $subscriptionResumed,
    ) {}
}

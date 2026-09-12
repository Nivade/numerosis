<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Auth;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * `$tenantId` is null for the central admin role and set for a tenant one.
 * Without it a listener cannot tell the two apart, since the tenant-side
 * dispatch happens inside `runInTenant()` and carries no other context.
 */
class AdminGranted
{
    use Dispatchable;

    public function __construct(
        public readonly string $globalId,
        public readonly ?string $grantedBy,
        public readonly ?string $tenantId = null,
    ) {}
}

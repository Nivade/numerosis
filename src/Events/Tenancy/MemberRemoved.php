<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Tenancy;

use Illuminate\Foundation\Events\Dispatchable;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;

class MemberRemoved
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $globalUserId,
        public readonly MembershipRole $role,
    ) {}
}

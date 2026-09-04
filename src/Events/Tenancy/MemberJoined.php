<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Tenancy;

use Illuminate\Foundation\Events\Dispatchable;

class MemberJoined
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $globalUserId,
        public readonly string $role,
        public readonly ?string $invitedBy,
    ) {}
}

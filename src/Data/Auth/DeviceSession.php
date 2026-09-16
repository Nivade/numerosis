<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Auth;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

class DeviceSession extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly CarbonImmutable $lastActiveAt,
        public readonly ?string $tenantId,
    ) {}
}

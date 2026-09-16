<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Auth;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched once per lockout window when the login limiter is exhausted.
 * Core ships no listener: a host wires this to its own alerting.
 */
class SuspiciousLoginDetected
{
    use Dispatchable;

    public function __construct(
        public readonly string $email,
        public readonly ?string $tenantKey,
        public readonly ?string $ip,
    ) {}
}

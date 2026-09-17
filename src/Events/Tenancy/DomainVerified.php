<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Tenancy;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Ownership of a custom domain has just been proven. Fired once per transition,
 * not once per check, so a listener may act on it — a Cloudflare for SaaS host
 * calls their API from here.
 */
class DomainVerified implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $domain,
        public readonly string $tenantId,
        public readonly bool $pointedHere,
    ) {}
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Tenancy;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A domain that was being served no longer is: its DNS was pulled, or an
 * operator revoked the claim. A host issuing certificates elsewhere listens here
 * to stop presenting one.
 */
class DomainRevoked implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $domain,
        public readonly string $tenantId,
        public readonly string $reason,
    ) {}
}

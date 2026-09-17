<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Auth;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Carries the global id and nothing else: by the time this fires there is no
 * name or address left to carry, which is the point of the operation.
 */
class UserAnonymized implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $tenantIds
     */
    public function __construct(
        public readonly string $globalId,
        public readonly array $tenantIds,
    ) {}
}

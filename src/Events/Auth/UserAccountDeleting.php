<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Auth;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvade\Numerosis\Models\Central\CentralUser;

/**
 * Dispatched before the row is deleted, so a synchronous listener can still
 * read `$user->tenants`. A queued one cannot: `SerializesModels` unserializes
 * `$user` after the delete has committed and throws `ModelNotFoundException`.
 * Read what you need here and hand it to the queue yourself, or listen for
 * `UserAccountDeleted`.
 */
class UserAccountDeleting
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly CentralUser $user,
        public readonly string $globalId,
    ) {}
}

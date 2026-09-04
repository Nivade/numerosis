<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Auth;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvade\Numerosis\Models\Central\CentralUser;

/**
 * Dispatched before the row is deleted, so a listener can still read
 * `$user->tenants` and everything else that belongs to it.
 *
 * That only works for a synchronous listener. A queued one unserializes
 * `$user` through `SerializesModels` after the delete has already committed
 * and gets a `ModelNotFoundException`; read what you need here and hand it to
 * the queue yourself, or listen for `UserAccountDeleted` instead.
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

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Auth;

use Stancl\Tenancy\Contracts\SyncMaster;

/**
 * @property-read string $global_id
 * @property-read string|null $email
 * @property-read string $name
 */
interface CentralUserModel extends SyncMaster
{
    /**
     * Untyped because `Illuminate\Notifications\Notifiable` declares it that
     * way, and an interface stricter than the trait satisfying it is a fatal
     * error.
     *
     * @param  mixed  $instance
     * @return void
     */
    public function notify($instance);
}

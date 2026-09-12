<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Auth;

use Stancl\Tenancy\Contracts\SyncMaster;

/**
 * @property-read string $global_id
 * @property-read string $email
 * @property-read string $name
 */
interface CentralUserModel extends SyncMaster
{
    /**
     * @param  mixed  $instance
     * @return void
     */
    public function notify($instance);
}

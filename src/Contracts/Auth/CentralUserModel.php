<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Auth;

use Nvade\Numerosis\Support\Compat\Tenancy\SyncMaster;

/**
 * @property-read string $global_id
 * @property-read string $email
 * @property-read string $name
 */
interface CentralUserModel extends SyncMaster {}

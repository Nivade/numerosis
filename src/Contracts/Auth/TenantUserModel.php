<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Auth;

use Stancl\Tenancy\Contracts\Syncable;

/**
 * @property-read string $global_id
 * @property-read string $email
 * @property-read string $name
 */
interface TenantUserModel extends Syncable {}

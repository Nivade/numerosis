<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Auth;

use Nvade\Numerosis\Models\Central\CentralUser;

/**
 * Creates the central user for a new registration. Bind a replacement in a
 * service provider to change how a registering user is stored (extra
 * profile fields, an external identity provider) without forking the
 * registration component.
 */
interface CreatesRegisteredUser
{
    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function create(array $data): CentralUser;
}

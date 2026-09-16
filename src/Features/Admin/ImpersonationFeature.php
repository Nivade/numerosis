<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Admin;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Features\Concerns\IsNamedFeature;

/**
 * Staff signing in as a tenant user, off by default. When absent the redeem
 * and exit routes are not registered and no token can be minted; the
 * `impersonate tenants` permission is seeded either way, since spatie throws
 * for a permission name that does not exist rather than denying it.
 */
class ImpersonationFeature implements NamedFeature
{
    use IsNamedFeature;

    public const NAME = 'impersonation';
}

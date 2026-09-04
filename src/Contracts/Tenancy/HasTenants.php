<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;

interface HasTenants
{
    /**
     * Concrete `Tenant`, never stancl's, because callers need `subscriptions()`.
     *
     * @return BelongsToMany<Tenant, static, Membership, 'pivot'>
     */
    public function tenants(): BelongsToMany;
}

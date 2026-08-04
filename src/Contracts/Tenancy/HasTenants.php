<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

interface HasTenants
{
    /**
     * Concrete `Tenant`, not stancl's — callers need `subscriptions()`.
     *
     * @return BelongsToMany<Tenant, static, Membership, 'pivot'>
     */
    public function tenants(): BelongsToMany;
}

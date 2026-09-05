<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;

interface HasTenants
{
    /**
     * Concrete `Tenant`, never stancl's, because callers need `subscriptions()`.
     * `Model`, not `static`, for the second parameter: `BelongsToMany`'s
     * `TDeclaringModel` is invariant, so an interface cannot declare "narrows
     * to whichever class implements this".
     *
     * @return BelongsToMany<Tenant, Model, Membership, 'pivot'>
     */
    public function tenants(): BelongsToMany;
}

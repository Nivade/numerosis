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
     * `Model`, not `static`, for the second parameter: an interface is not a
     * `Model`, so `static` resolves to `static(HasTenants)` and
     * `TDeclaringModel` rejects it. {@see \Nvade\Numerosis\Contracts\Subscribable}.
     *
     * @return BelongsToMany<Tenant, Model, Membership, 'pivot'>
     */
    public function tenants(): BelongsToMany;
}

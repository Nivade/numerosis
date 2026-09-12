<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Illuminate\Database\Eloquent\Model;
use Nvade\Numerosis\Contracts\Subscribable;

interface BillableResolver
{
    /**
     * The two shapes a resolved billable actually takes: a central user
     * (also `Subscribable`, via `BillableUser`) or a `Tenant` swapping its
     * own plan, which is `Subscribable` only.
     */
    public function resolve(): null|(Model&BillableUser)|(Model&Subscribable);
}

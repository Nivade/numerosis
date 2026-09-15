<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Illuminate\Database\Eloquent\Model;
use Nvade\Numerosis\Contracts\Subscribable;

interface BillableResolver
{
    /**
     * A central user or a `Tenant` swapping its own plan. `BillableUser`
     * extends `Subscribable`, so the Cashier paths needing more narrow on it
     * themselves.
     */
    public function resolve(): null|(Model&Subscribable);
}

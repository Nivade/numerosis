<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Billable;

interface BillableResolver
{
    /**
     * Billable is a trait, so PHP can't express the intersection natively —
     * the real contract is "a Model that uses Laravel\Cashier\Billable".
     *
     * @return (Model&Billable)|null
     */
    public function resolve(): ?Model;
}

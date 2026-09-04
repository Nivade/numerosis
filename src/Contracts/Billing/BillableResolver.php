<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Billable;

interface BillableResolver
{
    /**
     * Billable is a trait, so PHP cannot express the intersection natively.
     * The real contract is "a Model that uses Laravel\Cashier\Billable".
     *
     * @return (Model&Billable)|null
     */
    public function resolve(): ?Model;
}

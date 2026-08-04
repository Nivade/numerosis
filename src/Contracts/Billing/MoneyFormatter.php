<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

interface MoneyFormatter
{
    /**
     * @param  int  $amount  Minor currency units (cents) — what Cashier's own formatting expects.
     */
    public function format(int $amount, ?string $currency = null): string;
}

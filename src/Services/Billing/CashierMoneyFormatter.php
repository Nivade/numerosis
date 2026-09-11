<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing;

use Illuminate\Support\Facades\Config;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Contracts\Billing\MoneyFormatter;

class CashierMoneyFormatter implements MoneyFormatter
{
    public function format(int $amount, ?string $currency = null): string
    {
        return Cashier::formatAmount(
            amount: $amount,
            currency: $currency ?? Config::string('cashier.currency', 'usd'),
            options: ['min_fraction_digits' => 2],
        );
    }
}

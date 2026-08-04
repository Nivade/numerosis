<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing\Resolvers;

use Nvade\Numerosis\Contracts\Billing\MoneyFormatter;
use Illuminate\Support\Facades\Config;
use Laravel\Cashier\Cashier;

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

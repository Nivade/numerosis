<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Services\Billing;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Facades\Billing;
use Nvade\Numerosis\Tests\TestCase;

class BillingServiceFormatAmountTest extends TestCase
{
    public function test_it_formats_minor_units_in_the_configured_currency(): void
    {
        Config::set('cashier.currency', 'usd');

        $this->assertSame('$10.00', Billing::formatAmount(1000));
    }

    public function test_an_explicit_currency_wins_over_the_configured_one(): void
    {
        Config::set('cashier.currency', 'usd');

        $this->assertSame('€10.00', Billing::formatAmount(1000, 'eur'));
    }

    public function test_it_keeps_two_fraction_digits_on_a_whole_amount(): void
    {
        $this->assertSame('$1.05', Billing::formatAmount(105, 'usd'));
    }
}

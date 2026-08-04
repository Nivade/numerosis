<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Tests\TestCase;

class AccountPagesFeatureTest extends TestCase
{
    public function test_it_registers_the_account_routes_when_enabled(): void
    {
        $this->assertTrue(Route::has('settings.profile'));
        $this->assertTrue(Route::has('settings.appearance'));
        $this->assertTrue(Route::has('tenants.mine'));
        $this->assertTrue(Route::has('billing-portal'));
    }
}

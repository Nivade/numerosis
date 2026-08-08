<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Tests\TestCase;

class AccountPagesDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        Features::forceForTesting([]);

        parent::setUp();
    }

    public function test_it_registers_no_account_routes_when_disabled(): void
    {
        $this->assertFalse(Route::has('settings.profile'));
        $this->assertFalse(Route::has('settings.password'));
        $this->assertFalse(Route::has('settings.appearance'));
        $this->assertFalse(Route::has('tenants.mine'));
        $this->assertFalse(Route::has('billing-portal'));
    }
}

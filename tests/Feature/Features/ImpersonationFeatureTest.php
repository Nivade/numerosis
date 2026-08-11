<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Tests\TestCase;
use Stancl\Tenancy\Features\UserImpersonation;

class ImpersonationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_the_impersonate_route_when_enabled(): void
    {
        $this->assertTrue(Route::has('impersonate'));
    }

    public function test_it_registers_stancls_user_impersonation_feature(): void
    {
        $this->assertContains(UserImpersonation::class, Config::array('tenancy.features'));
    }
}

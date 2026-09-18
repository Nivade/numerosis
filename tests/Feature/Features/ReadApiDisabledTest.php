<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Tests\TestCase;

class ReadApiDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([]);

        parent::setUp();
    }

    public function test_it_registers_no_api_route_when_disabled(): void
    {
        $this->assertFalse(Route::has('numerosis.api.v1.tenant'));
        $this->assertFalse(Route::has('numerosis.api.v1.members'));
        $this->assertFalse(Route::has('numerosis.api.v1.invitations'));
        $this->assertFalse(Route::has('numerosis.api.v1.subscription'));
        $this->assertFalse(Route::has('numerosis.api.v1.domains'));
    }

    public function test_it_registers_no_token_screen_when_disabled(): void
    {
        $this->assertFalse(Route::has('api-tokens.index'));
    }
}

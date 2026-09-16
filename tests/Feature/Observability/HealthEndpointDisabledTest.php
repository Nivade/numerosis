<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Observability;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Tests\TestCase;

class HealthEndpointDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([]);

        parent::setUp();
    }

    public function test_the_route_is_absent_while_the_feature_is_off(): void
    {
        $this->assertFalse(Route::has('numerosis.health'));

        $this->get('/'.trim(Config::string('numerosis.routes.health_path'), '/'))->assertNotFound();
    }
}

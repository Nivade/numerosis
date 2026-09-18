<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Tests\TestCase;

class NotificationPreferencesDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([]);

        parent::setUp();
    }

    public function test_it_registers_no_route_when_disabled(): void
    {
        $this->assertFalse(Route::has('settings.notifications'));
    }
}

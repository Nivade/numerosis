<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Staff;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The panel is off by default, and "off" means nothing registered at all —
 * not a screen that 403s.
 */
class StaffPanelDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([]);

        parent::setUp();
    }

    public function test_no_staff_route_is_registered(): void
    {
        $names = array_keys(Route::getRoutes()->getRoutesByName());

        $staff = array_values(array_filter($names, fn (string $name): bool => str_starts_with($name, 'staff.')));

        $this->assertSame([], $staff);
    }

    public function test_the_staff_prefix_serves_nothing(): void
    {
        $this->get('/'.Config::string('numerosis.routes.staff_prefix').'/tenants')->assertNotFound();
    }

    public function test_the_feature_ships_disabled(): void
    {
        FeatureRegistry::forceForTesting(null);

        $this->assertFalse(FeatureRegistry::enabled('staff_panel'));
    }
}

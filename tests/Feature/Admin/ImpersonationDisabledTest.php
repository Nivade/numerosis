<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Policies\Tenancy\TenantPolicy;
use Nvade\Numerosis\Tests\TestCase;

class ImpersonationDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([]);

        parent::setUp();
    }

    public function test_neither_route_is_registered(): void
    {
        $this->assertFalse(Route::has('impersonate.redeem'));
        $this->assertFalse(Route::has('impersonate.exit'));
    }

    /**
     * Seeded either way: spatie throws `PermissionDoesNotExist` for a name
     * that does not exist, so a host enabling the feature on an already-seeded
     * database would otherwise get a 500 rather than a hidden button.
     */
    public function test_the_ability_is_seeded_anyway(): void
    {
        (new RoleAndPermissionSeeder)->run();

        $this->assertTrue(
            Permission::on('central')
                ->where('name', TenantPolicy::IMPERSONATE.' tenants')
                ->where('guard_name', 'web')
                ->exists()
        );
    }
}

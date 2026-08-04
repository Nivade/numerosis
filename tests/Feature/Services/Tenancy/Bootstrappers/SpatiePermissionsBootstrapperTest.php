<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Services\Tenancy\Bootstrappers;

use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Nvade\Numerosis\Tests\TestCase;

class SpatiePermissionsBootstrapperTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PermissionRegistrar keeps its hydrated permission collection in a plain
     * object property and loadPermissions() short-circuits whenever it is
     * already set, regardless of $cacheKey. The registrar is a singleton, so
     * without clearPermissionsCollection() on every bootstrap/revert, the
     * first tenant handled by a process pins its permission set for every
     * tenant handled afterwards by that same process — a queue worker, an
     * Octane worker, or (as here) a single-process test run.
     */
    public function test_permission_collection_does_not_leak_between_tenants_in_the_same_process(): void
    {
        $first = Tenant::create(['id' => 'perm-leak-first-'.uniqid()]);
        $second = Tenant::create(['id' => 'perm-leak-second-'.uniqid()]);

        $first->run(function () {
            Permission::create(['name' => 'first-tenant-only-permission', 'guard_name' => 'tenant']);
        });

        // Loads tenant A's permissions into the registrar's in-memory collection.
        $first->run(function () {
            $this->assertTrue(
                app(PermissionRegistrar::class)->getPermissions()->contains('name', 'first-tenant-only-permission')
            );
        });

        // Tenant B never created that permission. If the in-memory collection
        // survives the switch, it still reports the permission as present.
        $second->run(function () {
            $this->assertFalse(
                app(PermissionRegistrar::class)->getPermissions()->contains('name', 'first-tenant-only-permission'),
                'Tenant B was served tenant A\'s cached permission collection.'
            );
        });
    }
}

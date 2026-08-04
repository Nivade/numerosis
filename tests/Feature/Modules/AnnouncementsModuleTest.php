<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Modules;

use Nvade\Numerosis\Actions\Modules\MigrateModules;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nvade\Announcements\Models\Announcement;
use Nvade\Numerosis\Tests\TestCase;

class AnnouncementsModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrating_creates_the_announcements_table_on_the_tenant_only_and_seeds_permissions(): void
    {
        $tenant = Tenant::factory()->create();

        MigrateModules::dispatchSync($tenant, 'announcements');

        $this->assertFalse(Schema::hasTable('announcements'));

        $tenant->run(function () {
            $this->assertTrue(Schema::hasTable('announcements'));
            $this->assertTrue(
                Permission::where('name', 'view announcements')->where('guard_name', 'tenant')->exists()
            );
        });
    }

    public function test_active_scope_excludes_expired_and_upcoming_announcements(): void
    {
        $tenant = Tenant::factory()->create();
        MigrateModules::dispatchSync($tenant, 'announcements');

        $tenant->run(function () {
            $active = Announcement::factory()->create();
            $expired = Announcement::factory()->expired()->create();
            $upcoming = Announcement::factory()->upcoming()->create();

            $activeIds = Announcement::query()->active()->pluck('id');

            $this->assertTrue($activeIds->contains($active->id));
            $this->assertFalse($activeIds->contains($expired->id));
            $this->assertFalse($activeIds->contains($upcoming->id));

            $freshActive = $active->fresh();
            $freshExpired = $expired->fresh();
            $freshUpcoming = $upcoming->fresh();
            $this->assertNotNull($freshActive);
            $this->assertNotNull($freshExpired);
            $this->assertNotNull($freshUpcoming);

            $this->assertTrue($freshActive->isActive());
            $this->assertFalse($freshExpired->isActive());
            $this->assertFalse($freshUpcoming->isActive());
        });
    }
}

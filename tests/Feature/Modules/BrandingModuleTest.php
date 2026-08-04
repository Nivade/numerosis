<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Modules;

use Nvade\Numerosis\Actions\Modules\MigrateModules;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nvade\Branding\Models\BrandingSettings;
use Nvade\Numerosis\Tests\TestCase;

class BrandingModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrating_creates_the_branding_settings_table_on_the_tenant_only_and_seeds_permissions(): void
    {
        $tenant = Tenant::factory()->create();

        MigrateModules::dispatchSync($tenant, 'branding');

        $this->assertFalse(Schema::hasTable('branding_settings'));

        $tenant->run(function () {
            $this->assertTrue(Schema::hasTable('branding_settings'));
            $this->assertTrue(
                Permission::where('name', 'update branding')->where('guard_name', 'tenant')->exists()
            );
        });
    }

    public function test_current_is_a_single_row_repaired_by_firstorcreate(): void
    {
        $tenant = Tenant::factory()->create();
        MigrateModules::dispatchSync($tenant, 'branding');

        $tenant->run(function () {
            $first = BrandingSettings::current();
            $first->update(['primary_color' => '#ff0000']);

            $second = BrandingSettings::current();

            $this->assertSame($first->id, $second->id);
            $this->assertSame('#ff0000', $second->primary_color);
            $this->assertSame(1, BrandingSettings::query()->count());
        });
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Services\Billing\Modules;

use Nvade\Numerosis\Models\Central\ModuleOffering;
use Nvade\Numerosis\Services\Billing\Modules\EloquentModuleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\TestCase;

class EloquentModuleCatalogTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

    public function test_available_modules_are_cached(): void
    {
        $this->pinGlobalCache();

        ModuleOffering::factory()->count(2)->create(['available' => true]);

        $catalog = new EloquentModuleCatalog;
        $catalog->available();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'modules')) {
                $queries[] = $query->sql;
            }
        });

        $catalog->available();

        $this->assertEmpty($queries, 'A second call within the cache window should not have queried the database.');
    }

    public function test_saving_a_module_invalidates_the_cache(): void
    {
        $this->pinGlobalCache();

        $catalog = new EloquentModuleCatalog;
        $module = ModuleOffering::factory()->create(['available' => true]);

        $this->assertCount(1, $catalog->available());

        $module->update(['available' => false]);

        $this->assertCount(0, $catalog->available());
    }

    /**
     * Every purchase path resolves its module through findBySlug() from a
     * client-supplied slug. An unscoped lookup would leave a retired module
     * purchasable at its old price by anyone who remembered the slug.
     */
    public function test_find_by_slug_refuses_a_retired_module(): void
    {
        ModuleOffering::factory()->create(['slug' => 'legacy-tasks', 'available' => false]);

        $this->assertNull((new EloquentModuleCatalog)->findBySlug('legacy-tasks'));
    }

    public function test_find_by_slug_returns_an_available_module(): void
    {
        ModuleOffering::factory()->create(['slug' => 'tasks', 'available' => true]);

        $this->assertNotNull((new EloquentModuleCatalog)->findBySlug('tasks'));
    }

    /**
     * Admin and reporting paths still have to describe a tenant still paying
     * for a module that is no longer sold.
     */
    public function test_find_any_by_slug_still_returns_a_retired_module(): void
    {
        ModuleOffering::factory()->create(['slug' => 'legacy-tasks', 'available' => false]);

        $this->assertNotNull((new EloquentModuleCatalog)->findAnyBySlug('legacy-tasks'));
    }
}

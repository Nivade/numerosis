<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Concerns;

use Nvade\Numerosis\Concerns\InteractsWithTenantModules;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Module;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Tests\TestCase;

class InteractsWithTenantModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_an_enabled_module_and_memoises_the_query(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->run(function () {
            Module::create(['name' => 'alerts', 'enabled' => true]);
            Module::create(['name' => 'other', 'enabled' => false]);

            $consumer = new class
            {
                use InteractsWithTenantModules;
            };

            $this->assertTrue($consumer->isModuleEnabled('alerts'));
            $this->assertFalse($consumer->isModuleEnabled('other'));

            $queries = [];
            DB::listen(function ($query) use (&$queries) {
                if (str_contains($query->sql, 'modules')) {
                    $queries[] = $query->sql;
                }
            });

            // A second lookup on the same instance must not re-query — the
            // whole point of memoising the enabled set once per request
            // instead of once per plugin.
            $consumer->isModuleEnabled('alerts');

            $this->assertEmpty($queries);
        });
    }
}

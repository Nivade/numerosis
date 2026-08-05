<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Jobs;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Modules\MigrateModules;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Tests\TestCase;
use RuntimeException;

class MigrateModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_migrates_then_seeds_the_module(): void
    {
        $tenant = Tenant::factory()->create();

        MigrateModules::dispatchSync($tenant, 'alerts');

        $tenant->run(function () {
            $this->assertTrue(
                Permission::where('name', 'view alerts')->where('guard_name', 'tenant')->exists()
            );
        });
    }

    public function test_a_failed_migration_throws_instead_of_seeding(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tenants:migrate-module failed');

        MigrateModules::dispatchSync($tenant, 'does-not-exist');
    }
}

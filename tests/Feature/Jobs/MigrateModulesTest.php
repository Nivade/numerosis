<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Jobs;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Modules\MigrateModules;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

class MigrateModulesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Needs a real app-modules/alerts package installed on the node to get
     * past MigrateModules' "Module [x] not found" guard — structurally a
     * thin-app concern (numerosis ships the module system, not modules).
     */
    #[Group('thin-app')]
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

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Console\Commands;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * See .claude/plans/module-marketplace.md — permissions are seeded, not
 * migrated, so a module ships a `<Name>PermissionSeeder` and this command
 * runs it inside each tenant through modular's `db:seed --module` override.
 */
class SeedTenantModuleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Needs a real app-modules/alerts package on the node: the command
     * resolves the module *before* it looks at --tenants, so with no
     * `alerts` module installed this exits 1 on the module guard and never
     * reaches the "unknown tenant" path it means to cover. Structurally a
     * thin-app concern — numerosis ships the module system, not the modules.
     */
    #[Group('thin-app')]
    public function test_it_runs_nothing_for_an_unknown_tenant(): void
    {
        Tenant::unsetEventDispatcher();

        /** @var PendingCommand $command */
        $command = $this->artisan('tenants:seed-module', [
            'module' => 'alerts',
            '--tenants' => ['does-not-exist'],
        ]);
        $command->assertExitCode(0)->execute();
    }

    public function test_it_fails_for_an_unknown_module(): void
    {
        Tenant::unsetEventDispatcher();
        $tenant = Tenant::factory()->create();

        /** @var PendingCommand $command */
        $command = $this->artisan('tenants:seed-module', [
            'module' => 'does-not-exist',
            '--tenants' => [$tenant->getTenantKey()],
        ]);
        $command->assertExitCode(1)->execute();
    }

    #[Group('thin-app')]
    public function test_it_seeds_a_modules_permissions_into_the_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        /** @var PendingCommand $command */
        $command = $this->artisan('tenants:seed-module', [
            'module' => 'alerts',
            '--tenants' => [$tenant->getTenantKey()],
        ]);
        $command->assertExitCode(0)->execute();

        $tenant->run(function () {
            $this->assertTrue(
                Permission::where('name', 'view alerts')->where('guard_name', 'tenant')->exists()
            );
        });
    }
}

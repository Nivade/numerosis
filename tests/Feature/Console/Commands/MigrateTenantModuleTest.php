<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Console\Commands;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Regression coverage for the command's second rewrite: `module:migrate`,
 * the command it used to delegate to, does not exist in the installed
 * internachi/modular version at all (checked: `php artisan list` has no
 * `module:migrate`). It now mirrors RollbackTenantModule — core `migrate`
 * given an explicit `--path` into the module's `database/migrations/tenant`
 * directory, run inside `$tenant->run()`. See
 * .claude/plans/module-marketplace.md.
 */
class MigrateTenantModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_nothing_for_an_unknown_tenant(): void
    {
        Tenant::unsetEventDispatcher();

        /** @var PendingCommand $command */
        $command = $this->artisan('tenants:migrate-module', [
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
        $command = $this->artisan('tenants:migrate-module', [
            'module' => 'does-not-exist',
            '--tenants' => [$tenant->getTenantKey()],
        ]);
        $command->assertExitCode(1)->execute();
    }

    /**
     * The bug this rewrite fixes: MigratorPlugin globs `database/migrations`
     * non-recursively, so a migration under `database/migrations/tenant/`
     * is invisible to it and never runs against central. This asserts the
     * inverse holds too — the tenant migration actually reaches the tenant
     * database and never touches central.
     */
    public function test_it_migrates_a_module_against_the_tenant_database_only(): void
    {
        $tenant = Tenant::factory()->create();

        /** @var PendingCommand $command */
        $command = $this->artisan('tenants:migrate-module', [
            'module' => 'alerts',
            '--tenants' => [$tenant->getTenantKey()],
        ]);
        $command->assertExitCode(0)->execute();

        $tenant->run(function () {
            $this->assertTrue(
                DB::table('migrations')->where('migration', 'like', '%set_up_alerts_module%')->exists(),
                'The module migration should have run against the tenant database.'
            );
        });

        $this->assertFalse(
            DB::connection('central')->table('migrations')->where('migration', 'like', '%set_up_alerts_module%')->exists(),
            'The module migration should never reach the central migrations table.'
        );
    }
}

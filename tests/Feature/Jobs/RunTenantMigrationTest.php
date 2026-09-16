<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Jobs;

use App\Models\Central\TenantMigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Nvade\Numerosis\Enums\Tenancy\MigrationRunStatus;
use Nvade\Numerosis\Jobs\RunTenantMigration;
use Nvade\Numerosis\Tests\Support\FailingTenantMigration;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;
use RuntimeException;

class RunTenantMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        FailingTenantMigration::reset();

        parent::tearDown();
    }

    public function test_it_migrates_its_tenant_and_records_what_it_applied(): void
    {
        $tenant = TestTenant::provisioned(['id' => 'jobtenant']);

        $this->addMigrationPath('tenant-extra');

        new RunTenantMigration('run-one', 'jobtenant')->handle();

        $this->assertTrue($tenant->runHere(fn (): bool => Schema::hasTable('fleet_probes')) === true);

        $leg = TenantMigrationRun::query()->where('run_id', 'run-one')->firstOrFail();

        $this->assertSame(MigrationRunStatus::Succeeded, $leg->status);
        $this->assertSame(['2026_09_17_000000_create_fleet_probes_table'], $leg->migrations);
        $this->assertNotNull($leg->finished_at);
    }

    /**
     * The job rethrows so the queue records it too, but the leg row is what
     * the staff screen and `--resume` read.
     */
    public function test_a_failure_is_recorded_on_the_leg_before_it_is_rethrown(): void
    {
        TestTenant::provisioned(['id' => 'jobfails']);

        FailingTenantMigration::$tenantId = 'jobfails';

        $this->addMigrationPath('tenant-hostile');

        try {
            new RunTenantMigration('run-two', 'jobfails')->handle();
            $this->fail('The job should have rethrown the migration failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('this tenant cannot be migrated', $e->getMessage());
        }

        $leg = TenantMigrationRun::query()->where('run_id', 'run-two')->firstOrFail();

        $this->assertSame(MigrationRunStatus::Failed, $leg->status);
        $this->assertStringContainsString('this tenant cannot be migrated', (string) $leg->error);
        $this->assertFalse(tenancy()->initialized);
    }

    public function test_it_retries_nothing(): void
    {
        $this->assertSame(1, new RunTenantMigration('run-three', 'whatever')->tries);
    }

    private function addMigrationPath(string $directory): void
    {
        $parameters = Config::array('tenancy.migration_parameters');

        /** @var list<string> $paths */
        $paths = $parameters['--path'] ?? [];

        Config::set('tenancy.migration_parameters', [
            ...$parameters,
            '--path' => [...$paths, dirname(__DIR__, 2).'/Support/migrations/'.$directory],
            '--realpath' => true,
        ]);
    }
}

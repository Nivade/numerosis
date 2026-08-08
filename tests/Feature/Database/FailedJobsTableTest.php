<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvade\Numerosis\Tests\TestCase;
use RuntimeException;

/**
 * Guards against a regression of the bug fixed alongside this test: the
 * `failed_jobs` table was dropped in 2026_01_07_195854_remove_redundant_tables
 * and never recreated, while QUEUE_FAILED_DRIVER (default database-uuids)
 * still tried to write to it — so `queue:work`'s own failure-handling code
 * threw on every exhausted job. See .claude/plans/exception-handling.md,
 * Phase 6.
 */
class FailedJobsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_jobs_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('failed_jobs'));
    }

    public function test_the_failed_job_provider_can_log_a_failure(): void
    {
        /** @var FailedJobProviderInterface $failer */
        $failer = resolve('queue.failer');

        $uuid = $failer->log(
            'redis',
            'default',
            json_encode(['uuid' => (string) Str::uuid(), 'job' => 'Nvade\Numerosis\\Jobs\\SomeJob'], JSON_THROW_ON_ERROR),
            new RuntimeException('boom')
        );

        $this->assertNotNull($uuid);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
    }
}

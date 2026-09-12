<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Testing;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\TestCase;
use ReflectionClass;

/**
 * Central writes are not rolled back by `RefreshDatabase` — it transacts the
 * default connection only — so `CleansUpTenancyDatabases` tracks them with a
 * `DB::listen()` and deletes them in teardown.
 *
 * That listener belongs to the application it was registered against, and
 * `refreshApplication()` builds a new one. Without re-arming, every central
 * row written after a mid-test refresh survived into the next test in the same
 * worker, which reads as a flake because it depends on what the runner
 * schedules next: `PromoteFirstCentralUserToAdminTest` fails if and only if it
 * lands behind a test that refreshed.
 */
class CleansUpAfterRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_central_write_after_a_refresh_is_still_tracked(): void
    {
        $this->refreshApplication();

        CentralUser::factory()->create();

        $this->assertContains(
            'users',
            array_keys($this->trackedCentralTables()),
            'A central write after refreshApplication() was not tracked, so it would survive teardown and leak into the next test.',
        );
    }

    /**
     * @return array<string, true>
     */
    private function trackedCentralTables(): array
    {
        $property = (new ReflectionClass(TestCase::class))
            ->getProperty('dirtyCentralTables');

        /** @var array<string, true> */
        return $property->getValue($this);
    }
}

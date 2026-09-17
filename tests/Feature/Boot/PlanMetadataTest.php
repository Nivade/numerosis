<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Boot;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Nvade\Numerosis\Boot\PlanMetadata;
use Nvade\Numerosis\Database\Seeders\DatabaseSeeder;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `NumerosisServiceProvider::assertPlanMetadataIsWellShaped()` calls
 * {@see PlanMetadata::assertWellShaped()} on every console boot, on the same
 * terms as `Boot\ConfiguredSteps` — a typo used to stay silent until somebody
 * ran `numerosis:install`.
 */
class PlanMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeded_plans_pass(): void
    {
        (new DatabaseSeeder)->run();

        PlanMetadata::assertWellShaped();

        $this->assertSame(['failures' => [], 'warnings' => []], PlanMetadata::check());
    }

    public function test_a_non_numeric_seat_limit_fails_the_boot_check(): void
    {
        (new DatabaseSeeder)->run();

        PaymentPlan::query()->firstOrFail()->update([
            'metadata' => ['options' => ['max_users' => 'lots']],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/reads as uncapped/');

        PlanMetadata::assertWellShaped();
    }
}

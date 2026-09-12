<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Nvade\Numerosis\Contracts\Tenancy\ProvisioningStep;
use Nvade\Numerosis\Models\Central\TenantProvision;

/**
 * A step that does nothing but count how often it ran.
 *
 * Comparing `step_records` across two dispatches cannot answer that: a step
 * re-running writes the same outcome, and `at` is second-granular, so a
 * re-run inside the same second is invisible.
 */
class CountingStep implements ProvisioningStep
{
    public static int $runs = 0;

    public static function reset(): void
    {
        self::$runs = 0;
    }

    public function handle(TenantProvision $provision): void
    {
        self::$runs++;
    }
}

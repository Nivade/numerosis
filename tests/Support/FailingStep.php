<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Nvade\Numerosis\Contracts\Tenancy\ProvisioningStep;
use Nvade\Numerosis\Models\Central\TenantProvision;
use RuntimeException;

/**
 * Stands in for a step that fails permanently, then stops failing, so a test
 * can watch a chain resume across two dispatches.
 *
 * Counts its own runs: "the step that failed ran again" and "the steps before
 * it did not" are the two halves of resumability, and only the first is
 * visible from the provision row.
 */
class FailingStep implements ProvisioningStep
{
    public static bool $shouldFail = true;

    public static int $runs = 0;

    public static function reset(bool $shouldFail = true): void
    {
        self::$shouldFail = $shouldFail;
        self::$runs = 0;
    }

    public function handle(TenantProvision $provision): void
    {
        self::$runs++;

        throw_if(self::$shouldFail, new RuntimeException('migration failed permanently'));

        resolve(CloneTenantSchema::class)->handle($provision);
    }
}

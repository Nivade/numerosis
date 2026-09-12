<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Nvade\Numerosis\Contracts\Tenancy\ControlsItsOwnRetries;
use Nvade\Numerosis\Models\Central\TenantProvision;

/**
 * A host step that waits on something slower than the chain's default allows.
 */
class PatientHostStep implements ControlsItsOwnRetries
{
    public static function tries(): int
    {
        return 20;
    }

    public static function backoff(): int
    {
        return 60;
    }

    public function handle(TenantProvision $provision): void {}
}

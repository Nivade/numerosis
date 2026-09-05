<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Browser;

use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;

abstract class PathModeTestCase extends IdentificationModeTestCase
{
    protected function identificationMode(): IdentificationMode
    {
        return IdentificationMode::Path;
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Browser;

use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;

/**
 * Custom-domain mode alone matches the tenant against the literal `{tenant}`
 * pattern, resolved through `Tenant::resolveRouteBinding()` rather than `id`.
 */
abstract class CustomDomainModeTestCase extends IdentificationModeTestCase
{
    protected function identificationMode(): IdentificationMode
    {
        return IdentificationMode::CustomDomain;
    }
}

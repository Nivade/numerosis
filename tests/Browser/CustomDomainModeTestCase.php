<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Browser;

use Illuminate\Contracts\Config\Repository;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Boots the application in **custom domain** identification mode. See
 * `PathModeTestCase` for why this has to happen before boot rather than via
 * a `Config::set()` in the test body: the mode decides identification
 * middleware, whether the tenant panel gets a domain pattern, and — for this
 * mode specifically — that the pattern is the literal `{tenant}`, matched
 * against `Tenant::resolveRouteBinding()`'s override rather than `id`.
 */
abstract class CustomDomainModeTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app->make(Repository::class)->set(
            'numerosis.tenancy.identification.mode',
            IdentificationMode::CustomDomain->value,
        );
    }
}

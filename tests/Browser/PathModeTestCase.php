<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Browser;

use Illuminate\Contracts\Config\Repository;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Boots the application in **path** identification mode.
 *
 * The mode has to be chosen before the application boots — it decides which
 * identification middleware is registered and how tenant routes are scoped.
 * A `Config::set()` in a test body is too late for either, which is why this
 * is a base class and not a helper.
 */
abstract class PathModeTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app->make(Repository::class)->set(
            'numerosis.tenancy.identification.mode',
            IdentificationMode::Path->value,
        );
    }
}

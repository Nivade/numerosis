<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Browser;

use Illuminate\Contracts\Config\Repository;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Boots the application in one non-default identification mode.
 *
 * The mode has to be chosen before the application boots — it decides which
 * identification middleware is registered, how tenant routes are scoped, and
 * whether the tenant group gets a domain pattern at all. A `Config::set()` in
 * a test body is too late for any of that, which is why this is a base class
 * and not a helper.
 */
abstract class IdentificationModeTestCase extends TestCase
{
    abstract protected function identificationMode(): IdentificationMode;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app->make(Repository::class)->set(
            'numerosis.tenancy.identification.mode',
            $this->identificationMode()->value,
        );
    }
}

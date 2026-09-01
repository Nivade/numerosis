<?php

declare(strict_types=1);

namespace Nvade\NumerosisAccount\Tests;

use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\NumerosisAccount\NumerosisAccountServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Core is listed explicitly and first: this package registers into core's
 * feature registry and route seams while registering, and every screen it
 * ships is typed against core's models.
 *
 * There is deliberately no database here. The screens' real coverage lives in
 * core's suite, which already has the tenancy harness; this suite only proves
 * this package's own half of the wiring.
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            NumerosisServiceProvider::class,
            NumerosisAccountServiceProvider::class,
        ];
    }

    /**
     * Same reasoning as core's own suite: Testbench defaults this to `['*']`,
     * which rejects every vendor package's discovered providers *and* aliases
     * silently — Livewire's included.
     */
    public function ignorePackageDiscoveriesFrom(): array
    {
        return [];
    }
}

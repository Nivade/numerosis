<?php

declare(strict_types=1);

namespace Nvade\NumerosisAuthUi\Tests;

use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\NumerosisAuthUi\NumerosisAuthUiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Core is listed explicitly and first: this package's own
     * `packageRegistered()` writes into config keys core's
     * `mergeConfigFrom()` supplies (`numerosis.panels.*`), and reads
     * `Numerosis`/`Features` statics core owns.
     */
    protected function getPackageProviders($app): array
    {
        return [
            NumerosisServiceProvider::class,
            NumerosisAuthUiServiceProvider::class,
        ];
    }

    /**
     * Same reasoning as core's own suite: Testbench defaults this to `['*']`,
     * which rejects every vendor package's discovered providers *and* aliases
     * silently — `livewire/livewire`'s facade and `livewire.finder` binding
     * included. An unregistered Blade tag renders as literal text rather than
     * erroring, so leaving this at the default makes view assertions pass
     * vacuously.
     */
    public function ignorePackageDiscoveriesFrom(): array
    {
        return [];
    }
}

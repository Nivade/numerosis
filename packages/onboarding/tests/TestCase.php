<?php

declare(strict_types=1);

namespace Nvade\NumerosisOnboarding\Tests;

use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\NumerosisOnboarding\NumerosisOnboardingServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Core is listed explicitly and first: this package's own
     * `packageRegistered()` writes into a config key core's
     * `mergeConfigFrom()` supplies (`numerosis.tenancy.registration.steps`)
     * and reads `Numerosis`/`Features` statics core owns.
     */
    protected function getPackageProviders($app): array
    {
        return [
            NumerosisServiceProvider::class,
            NumerosisOnboardingServiceProvider::class,
        ];
    }

    /**
     * Same reasoning as core's own suite: Testbench defaults this to `['*']`,
     * which silently rejects every vendor package's discovered providers and
     * aliases — `livewire/livewire`'s facade and `livewire.finder` binding
     * included. An unregistered Blade tag renders as literal text rather than
     * erroring, so leaving the default makes view assertions pass vacuously.
     */
    public function ignorePackageDiscoveriesFrom(): array
    {
        return [];
    }
}

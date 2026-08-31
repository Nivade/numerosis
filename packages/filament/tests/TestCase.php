<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Tests;

use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\NumerosisFilament\NumerosisFilamentServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Core is listed explicitly and first: this package reads
     * `numerosis.panels.*` (core's config section) while registering, and
     * every resource it ships is typed against core's models.
     *
     * There is deliberately no database here. The panels' real coverage lives
     * in core's suite, which already has the tenancy harness; this suite only
     * proves this package's own half of the wiring.
     */
    protected function getPackageProviders($app): array
    {
        return [
            NumerosisServiceProvider::class,
            NumerosisFilamentServiceProvider::class,
        ];
    }

    /**
     * Same reasoning as core's own suite: Testbench defaults this to `['*']`,
     * which rejects every vendor package's discovered providers *and* aliases
     * silently — Filament's and Livewire's included.
     */
    public function ignorePackageDiscoveriesFrom(): array
    {
        return [];
    }
}

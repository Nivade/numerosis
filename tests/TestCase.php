<?php

declare(strict_types=1);

namespace Nvade\NumerosisUi\Tests;

use Nvade\NumerosisUi\NumerosisUiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            NumerosisUiServiceProvider::class,
        ];
    }

    /**
     * Testbench ignores every package's discovered providers and aliases by
     * default (`['*']`), which would leave Flux's own components unregistered
     * and make every assertion here vacuous — an unregistered Blade tag
     * renders as literal text rather than erroring. Same reasoning, and the
     * same trap, as the equivalent override in nvade/numerosis's suite.
     */
    public function ignorePackageDiscoveriesFrom(): array
    {
        return [];
    }
}

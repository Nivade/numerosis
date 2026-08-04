<?php

namespace Nvade\Numerosis;

use Nvade\Numerosis\Commands\NumerosisCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class NumerosisServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('numerosis')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_numerosis_table')
            ->hasCommand(NumerosisCommand::class);
    }
}

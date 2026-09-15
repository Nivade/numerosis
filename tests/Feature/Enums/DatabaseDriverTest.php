<?php

declare(strict_types=1);

use Nvade\Numerosis\Enums\Tenancy\DatabaseDriver;

/*
 * The two commands that guard on the driver read their accept/warn/refuse
 * answer from here, so the set of cases is the supported set.
 */

it('accepts every driver the package runs on', function (string $driver): void {
    expect(DatabaseDriver::tryFrom($driver))->not->toBeNull();
})->with(['mysql', 'mariadb', 'pgsql', 'sqlite']);

it('rejects a driver the package does not run on', function (): void {
    expect(DatabaseDriver::tryFrom('sqlsrv'))->toBeNull();
});

it('warns only about the driver with one writer per file', function (): void {
    expect(DatabaseDriver::Sqlite->allowsConcurrentWriters())->toBeFalse()
        ->and(DatabaseDriver::Sqlite->warning())->toContain('one writer per database file');

    foreach ([DatabaseDriver::Mysql, DatabaseDriver::Mariadb, DatabaseDriver::Pgsql] as $driver) {
        expect($driver->allowsConcurrentWriters())->toBeTrue()
            ->and($driver->warning())->toBeNull();
    }
});

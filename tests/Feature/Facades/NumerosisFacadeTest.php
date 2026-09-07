<?php

declare(strict_types=1);

use Nvade\Numerosis\Facades\Numerosis as NumerosisFacade;
use Nvade\Numerosis\Numerosis;

/*
 * Post-boot access only. routes()/middleware()/exceptions()/configure() run
 * before RegisterFacades, where getFacadeRoot() is null and every call throws
 * `A facade root has not been set` — the 2026-08-31 crash-loop recorded in
 * .ai/rules/package-host-bootstrap.md.
 */

afterEach(function (): void {
    NumerosisFacade::clearResolvedInstances();
});

it('resolves the support class as its facade root', function () {
    expect(NumerosisFacade::getFacadeRoot())->toBeInstanceOf(Numerosis::class);
});

it('forwards a static method called through the facade', function () {
    expect(NumerosisFacade::tenantMigrationPath())->toBe(Numerosis::tenantMigrationPath());
});

it('lets a host swap the instance', function () {
    NumerosisFacade::swap(new class extends Numerosis
    {
        public static function tenantMigrationPath(): string
        {
            return '/tmp/swapped-migrations';
        }
    });

    expect(NumerosisFacade::tenantMigrationPath())->toBe('/tmp/swapped-migrations');
});

/*
 * Mockery generates an instance method on a subclass, which wins over the
 * inherited `static` one that `Facade::__callStatic()` reaches as
 * `$instance->method()`. Asserted so a Mockery upgrade changing it is loud.
 */
it('intercepts an originally static method through shouldReceive', function () {
    NumerosisFacade::shouldReceive('tenantMigrationPath')->andReturn('/tmp/mocked');

    expect(NumerosisFacade::tenantMigrationPath())->toBe('/tmp/mocked');
});

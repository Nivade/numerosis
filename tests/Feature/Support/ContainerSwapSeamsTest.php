<?php

declare(strict_types=1);

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use Nvade\Numerosis\Contracts\Exceptions\ProvidesExceptionContext;
use Nvade\Numerosis\Http\Middleware\InitializeTenancy;
use Nvade\Numerosis\Services\Exceptions\TenantAwareExceptionContext;
use Nvade\Numerosis\Support\Numerosis;

/*
 * One swap mechanism, the container: every default numerosis supplies is
 * replaceable by a binding, with no config key and no registry.
 * `.claude/plans/effervescent-questing-pumpkin.md` §6.
 */

it('resolves the tenant aware exception context by default', function () {
    expect(resolve(ProvidesExceptionContext::class))->toBeInstanceOf(TenantAwareExceptionContext::class);
});

it('reports the context a host bound in place of the package one', function () {
    app()->bind(ProvidesExceptionContext::class, fn (): ProvidesExceptionContext => new class implements ProvidesExceptionContext
    {
        public function handle(): array
        {
            return ['host_key' => 'host_value'];
        }
    });

    $handler = new Handler(app());

    Numerosis::exceptions(new Exceptions($handler));

    $context = new ReflectionMethod($handler, 'buildExceptionContext')
        ->invoke($handler, new RuntimeException('probe'));

    expect($context)->toMatchArray(['host_key' => 'host_value']);
});

it('resolves middleware through the container, so a binding swaps the concrete', function () {
    app()->bind(InitializeTenancy::class, fn (): object => new class
    {
        public function handle(mixed $request, Closure $next): mixed
        {
            return $next($request);
        }
    });

    expect(resolve(InitializeTenancy::class))->not->toBeInstanceOf(InitializeTenancy::class);
});

it('replaces exception registration entirely when registerExceptionsUsing is set', function () {
    $called = null;

    Numerosis::registerExceptionsUsing(function (Exceptions $exceptions) use (&$called): void {
        $called = $exceptions;
    });

    $exceptions = new Exceptions(new Handler(app()));

    Numerosis::exceptions($exceptions);

    expect($called)->toBe($exceptions);
});

afterEach(function (): void {
    Numerosis::$registerExceptionsCallback = null;
});

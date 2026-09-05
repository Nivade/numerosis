<?php

declare(strict_types=1);

use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Routing\Router;
use Nvade\Numerosis\Http\Middleware\InitializeTenancy;
use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\Numerosis\Support\Numerosis;

/*
 * NumerosisServiceProvider::registerMiddleware() used to re-apply the
 * package's aliases, TrustProxies::at('*') and TrustHosts unconditionally
 * from packageBooted(), which runs after a host's withMiddleware() closure —
 * silently reverting whatever the host configured.
 */

function bootRegisterMiddleware(): void
{
    $provider = new NumerosisServiceProvider(app());

    new ReflectionMethod($provider, 'registerMiddleware')->invoke($provider);
}

function trustedProxies(): mixed
{
    return new ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies')->getValue();
}

afterEach(function (): void {
    Numerosis::resetMiddlewareRegisteredForTesting();
    TrustProxies::flushState();
});

it('leaves a host alias swap and trust config alone once Numerosis::middleware() has run', function () {
    Numerosis::middleware(new Middleware);

    $router = resolve(Router::class);
    $router->aliasMiddleware('tenancy.identification', HostReplacementMiddleware::class);
    TrustProxies::at(['10.0.0.0/8']);

    bootRegisterMiddleware();

    expect($router->getMiddleware()['tenancy.identification'])->toBe(HostReplacementMiddleware::class)
        ->and(trustedProxies())->toBe(['10.0.0.0/8']);
});

it('still self heals for a host that never called Numerosis::middleware()', function () {
    Numerosis::resetMiddlewareRegisteredForTesting();

    $router = resolve(Router::class);
    $router->aliasMiddleware('tenancy.identification', HostReplacementMiddleware::class);

    bootRegisterMiddleware();

    expect($router->getMiddleware()['tenancy.identification'])->toBe(InitializeTenancy::class)
        ->and(trustedProxies())->toBe('*');
});

class HostReplacementMiddleware
{
    public function handle(mixed $request, Closure $next): mixed
    {
        return $next($request);
    }
}

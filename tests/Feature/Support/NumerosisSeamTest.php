<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Http\Middleware\CheckInvitationStatus;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Nvade\Numerosis\Support\Numerosis;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
 * Every host-seam bug this extraction found lived in these methods (see
 * .claude/plans/post-extraction-review.md, Phase 3.3). This is the contract
 * between the package and bootstrap/app.php — nothing here should be able
 * to drift without a red test.
 */

uses(RefreshDatabase::class);

it('registers the 4 middleware aliases and both groups, tenant group in order', function () {
    $middleware = new Middleware;

    Numerosis::middleware($middleware);

    expect($middleware->getMiddlewareAliases())->toMatchArray([
        'invitation.status' => CheckInvitationStatus::class,
        'tenancy.identification' => TenancyServiceProvider::TENANCY_IDENTIFICATION,
        'tenancy.route' => PreventAccessFromCentralDomains::class,
        'tenancy.session' => EnsureSessionMatchesTenant::class,
    ]);

    $groups = $middleware->getMiddlewareGroups();

    expect($groups['tenant'])->toBe([
        'web',
        'tenancy.identification',
        'tenancy.route',
        'tenancy.session',
    ]);

    expect($groups)->toHaveKey('universal', []);
});

it('returns the exact broadcasting middleware list', function () {
    expect(Numerosis::broadcasting())->toBe([
        'web', 'tenancy.identification', 'tenancy.session', 'auth:tenant', 'universal',
    ]);
});

it('returns the exact csrf exceptions list', function () {
    expect(Numerosis::csrfExceptions())->toBe([
        'stripe/*', 'billing/webhook', 'telescope/*',
    ]);
});

it('binds a web-middleware route per central domain and a tenant group', function () {
    // routes() already ran once for the whole suite via TestCase::defineRoutes();
    // this asserts the outcome of that call, not a fresh invocation.
    $central = Route::getRoutes()->getByName('terms');
    throw_unless($central instanceof IlluminateRoute, RuntimeException::class, 'route [terms] not registered');

    expect($central->getDomain())->toBe(Config::array('tenancy.central_domains')[0]);
    expect($central->middleware())->toContain('web');

    $tenant = Route::getRoutes()->getByName('verification.notice');
    throw_unless($tenant instanceof IlluminateRoute, RuntimeException::class, 'route [verification.notice] not registered');

    expect($tenant->middleware())->toContain('tenant');
});

it('reports tenant_id/guard/user_global_id outside a tenant context', function () {
    $exceptions = new class(new Handler(app())) extends Exceptions
    {
        public ?Closure $captured = null;

        public function context(Closure $contextCallback)
        {
            $this->captured = $contextCallback;

            return $this;
        }
    };

    Numerosis::exceptions($exceptions);

    throw_unless($exceptions->captured instanceof Closure, RuntimeException::class, 'context callback was not captured');

    $context = ($exceptions->captured)();

    expect($context)->toHaveKeys(['tenant_id', 'guard', 'user_global_id']);
    expect($context['tenant_id'])->toBeNull();
});

it('reports the tenant key for tenant_id inside $tenant->run()', function () {
    $tenant = Tenant::factory()->create();

    $exceptions = new class(new Handler(app())) extends Exceptions
    {
        public ?Closure $captured = null;

        public function context(Closure $contextCallback)
        {
            $this->captured = $contextCallback;

            return $this;
        }
    };

    Numerosis::exceptions($exceptions);

    throw_unless($exceptions->captured instanceof Closure, RuntimeException::class, 'context callback was not captured');

    $callback = $exceptions->captured;

    $tenant->run(function () use ($callback, $tenant) {
        $context = $callback();

        expect($context['tenant_id'])->toBe((string) $tenant->getTenantKey());
    });
});

it('registers the middleware aliases/groups against the real router with no host bootstrap call', function () {
    // NumerosisServiceProvider::registerMiddleware() already ran during
    // package boot — nothing in this test calls Numerosis::middleware()
    // or touches bootstrap/app.php. Asserts the runtime path, not the pure
    // Middleware-object path the first test above covers.
    $router = resolve(Router::class);

    expect($router->getMiddleware())->toMatchArray([
        'invitation.status' => CheckInvitationStatus::class,
        'tenancy.identification' => TenancyServiceProvider::TENANCY_IDENTIFICATION,
        'tenancy.route' => PreventAccessFromCentralDomains::class,
        'tenancy.session' => EnsureSessionMatchesTenant::class,
    ]);

    expect($router->getMiddlewareGroups()['tenant'])->toBe([
        'web',
        'tenancy.identification',
        'tenancy.route',
        'tenancy.session',
    ]);

    $kernel = resolve(Kernel::class);
    $middlewareProperty = new ReflectionProperty($kernel, 'middleware');

    expect($middlewareProperty->getValue($kernel))->toContain(TrustHosts::class);
});

it('registers the broadcasting auth route and channels with no host bootstrap call', function () {
    $registered = collect(Route::getRoutes()->getRoutes())->contains(fn ($route): bool => $route->uri() === 'broadcasting/auth');

    expect($registered)->toBeTrue();

    $channels = Broadcast::getChannels();

    expect($channels->keys()->all())->toContain('online', 'user.{userId}');
});

it('wires context/throttling onto the real exception handler with no host bootstrap call', function () {
    $handler = resolve(ExceptionHandler::class);

    throw_unless($handler instanceof Handler, RuntimeException::class, 'exception handler is not the framework Handler');

    // buildContextForException() runs every registered contextCallback —
    // Numerosis::exceptions()'s closure among them if registerExceptionHandling()
    // wired it onto this exact singleton, which is the thing under test.
    $context = $handler->buildContextForException(new RuntimeException('probe'));

    expect($context)->toHaveKeys(['tenant_id', 'guard', 'user_global_id']);
});

it('points assetSourcePaths(), tenantMigrationPath() and broadcastChannelsPath() at real directories', function () {
    foreach (Numerosis::assetSourcePaths() as $source => $target) {
        expect(is_dir($source))->toBeTrue("expected {$source} to exist");
    }

    expect(is_dir(Numerosis::tenantMigrationPath()))->toBeTrue();
    expect(file_exists(Numerosis::broadcastChannelsPath()))->toBeTrue();
});

afterEach(function () {
    // These three statics persist for the life of the PHP process, not per
    // Application instance (see .claude/rules/testing.md's general warning
    // about static state) — a callback left set here would fire again for
    // every later test's own registerMiddleware()/registerBroadcasting()/
    // routes() call, most of which don't expect one.
    Numerosis::$registerRoutesCallback = null;
    Numerosis::$registerBroadcastingCallback = null;
    Numerosis::$registerMiddlewareCallback = null;
});

it('replaces middleware registration entirely when registerMiddlewareUsing is set', function () {
    // The real boot already ran registerMiddleware() once with no callback
    // set, so the package's aliases already exist by the time this test's
    // body runs — a presence check can't tell "replaced" from "ran twice".
    // Count the alias table instead: a second, overridden run must add
    // nothing to it.
    $aliasCountBefore = count(Route::getFacadeRoot()->getMiddleware());

    $called = null;

    Numerosis::registerMiddlewareUsing(function ($app) use (&$called): void {
        $called = $app;
    });

    $provider = new NumerosisServiceProvider(app());
    new ReflectionMethod($provider, 'registerMiddleware')->invoke($provider);

    expect($called)->toBe(app())
        ->and(Route::getFacadeRoot()->getMiddleware())->toHaveCount($aliasCountBefore);
});

it('replaces broadcasting registration entirely when registerBroadcastingUsing is set', function () {
    // Same reasoning as the middleware test above: broadcasting/auth is
    // already registered by the real boot before this test runs.
    $routeCountBefore = count(Route::getRoutes()->getRoutes());

    $called = null;

    Numerosis::registerBroadcastingUsing(function ($app) use (&$called): void {
        $called = $app;
    });

    $provider = new NumerosisServiceProvider(app());
    new ReflectionMethod($provider, 'registerBroadcasting')->invoke($provider);

    expect($called)->toBe(app())
        ->and(count(Route::getRoutes()->getRoutes()))->toBe($routeCountBefore);
});

it('replaces route registration entirely when registerRoutesUsing is set', function () {
    // Same reasoning: routes() already ran once (with no callback) during
    // the real boot, so 'terms'/'verification.notice' already exist —
    // proving replacement means the override adds nothing further, not
    // that the defaults are absent.
    $routeCountBefore = count(Route::getRoutes()->getRoutes());

    $called = null;

    Numerosis::registerRoutesUsing(function ($app) use (&$called): void {
        $called = $app;
    });

    Numerosis::routes();

    expect($called)->toBe(app())
        ->and(count(Route::getRoutes()->getRoutes()))->toBe($routeCountBefore);
});

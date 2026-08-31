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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Http\Middleware\CheckInvitationStatus;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Models\Central\Tenant as PackageTenant;
use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Nvade\Numerosis\Support\Numerosis;

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
        'tenancy.identification' => TenancyServiceProvider::identificationMiddleware(),
        'tenancy.route' => TenancyServiceProvider::tenancyRouteMiddleware(),
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

it('does not double-register onto the same Handler instance', function () {
    // The real scenario this guards: a host calling Numerosis::exceptions()
    // from its own bootstrap/app.php AND NumerosisServiceProvider::
    // registerExceptionHandling()'s fallback both firing against the one
    // real Handler singleton. Simulated here by calling it twice by hand
    // against the same $exceptions wrapper.
    $exceptions = new class(new Handler(app())) extends Exceptions
    {
        public int $contextCalls = 0;

        public function context(Closure $contextCallback)
        {
            $this->contextCalls++;

            return $this;
        }
    };

    Numerosis::exceptions($exceptions);
    Numerosis::exceptions($exceptions);

    expect($exceptions->contextCalls)->toBe(1);
});

it('registers the middleware aliases/groups against the real router with no host bootstrap call', function () {
    // NumerosisServiceProvider::registerMiddleware() already ran during
    // package boot — nothing in this test calls Numerosis::middleware()
    // or touches bootstrap/app.php. Asserts the runtime path, not the pure
    // Middleware-object path the first test above covers.
    $router = resolve(Router::class);

    expect($router->getMiddleware())->toMatchArray([
        'invitation.status' => CheckInvitationStatus::class,
        'tenancy.identification' => TenancyServiceProvider::identificationMiddleware(),
        'tenancy.route' => TenancyServiceProvider::tenancyRouteMiddleware(),
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

// Numerosis::configure() is deliberately untested here:
// Application::configure()'s first step is `new Application($basePath)`,
// and Application::registerBaseBindings() calls `static::setInstance($this)`
// unconditionally — constructing one, even without ever calling ->create()
// on the returned builder, replaces the container singleton every facade
// and app()/resolve() call in the *current* test process resolves through.
// Confirmed by trying it: the test itself passed, but corrupted state
// survived into whichever test ran next (parent::tearDown() failing with
// "Target class [config] does not exist"), since Container::getInstance()
// no longer pointed at the real test application. routes()/middleware()/
// exceptions() are each fully covered above already — configure() is a
// 3-line call to those three, not independently risky to leave uncovered.

it('assetTags() renders the prebuilt CSS+JS tags when nothing is published', function () {
    // Htmlable::toHtml() carries no return-type declaration on the
    // interface itself (only HtmlString's own concrete `@return string`
    // does), so PHPStan can only infer the interface's widest possible
    // type here — narrowed back to what the concrete implementation always
    // returns.
    /** @var string $html */
    $html = Numerosis::assetTags()->toHtml();

    // ->not is declared via @property on Pest\Expectation itself; chaining
    // it after ->toContain() (which returns self typed as the
    // Pest\Mixins\Expectation trait, not that outer class) loses visibility
    // of it. A fresh expect() call keeps ->not resolvable.
    expect($html)
        ->toContain('css/nvade/numerosis/'.NumerosisServiceProvider::ASSET_ID.'.css')
        ->toContain('js/nvade/numerosis/'.NumerosisServiceProvider::ASSET_ID.'.js');

    expect($html)->not->toContain('/build/assets/');
});

it('assetTags() falls back to the prebuilt JS tag when published but absent from the hosts Vite manifest', function () {
    File::ensureDirectoryExists(resource_path('js'));
    File::put(resource_path('js/numerosis.js'), '// published copy, not built');

    $html = Numerosis::assetTags()->toHtml();

    expect($html)->toContain('js/nvade/numerosis/'.NumerosisServiceProvider::ASSET_ID.'.js');
});

it('assetTags() prefers vite() when the host has published and built its own copy', function () {
    File::ensureDirectoryExists(resource_path('js'));
    File::put(resource_path('js/numerosis.js'), '// published copy, built');

    $manifestPath = public_path('build/manifest.json');
    /** @var array<string, mixed> $manifest */
    $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $manifest['resources/js/numerosis.js'] = [
        'file' => 'assets/numerosis-hashed.js',
        'src' => 'resources/js/numerosis.js',
        'isEntry' => true,
    ];
    File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

    // Illuminate\Foundation\Vite caches parsed manifests in a `protected
    // static $manifests` array keyed by path, populated on first read and
    // never invalidated for that path — since PHPUnit/Pest run every test
    // in one process, and TestCase::stubViteManifest() rewrites the same
    // physical path on every test's fresh app boot, whichever test in the
    // whole run first touches app(Vite::class) permanently caches that
    // path's content for every later test too. Cleared in this file's
    // afterEach() below, not just here, for exactly that reason.
    (new ReflectionProperty(Illuminate\Foundation\Vite::class, 'manifests'))->setValue(null, []);

    /** @var string $html */
    $html = Numerosis::assetTags()->toHtml();

    expect($html)->toContain('assets/numerosis-hashed.js');
    expect($html)->not->toContain('js/nvade/numerosis/'.NumerosisServiceProvider::ASSET_ID.'.js');
});

afterEach(function () {
    // Filesystem writes, unlike RefreshDatabase, survive past the test that
    // made them — a published resources/js/numerosis.js left behind would
    // silently flip a *later*, unrelated test onto the @vite() branch.
    File::delete(resource_path('js/numerosis.js'));

    // Illuminate\Foundation\Vite's manifest cache (see the "prefers vite()"
    // test above) is a process-lifetime static, not reset between
    // Testbench application rebuilds — reset unconditionally so this file's
    // manifest mutation never leaks into an unrelated, later test.
    (new ReflectionProperty(Illuminate\Foundation\Vite::class, 'manifests'))->setValue(null, []);
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
    $aliasCountBefore = count(resolve(Router::class)->getMiddleware());

    $called = null;

    Numerosis::registerMiddlewareUsing(function ($app) use (&$called): void {
        $called = $app;
    });

    $provider = new NumerosisServiceProvider(app());
    new ReflectionMethod($provider, 'registerMiddleware')->invoke($provider);

    expect($called)->toBe(app())
        ->and(resolve(Router::class)->getMiddleware())->toHaveCount($aliasCountBefore);
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

it('resolves a model by convention when App\Models\<suffix> exists and extends the package model, no config needed', function () {
    Numerosis::resetModelCache();
    Config::set('numerosis.models.'.PackageTenant::class, null);

    expect(Numerosis::model(PackageTenant::class))->toBe(Tenant::class);
});

it('lets an explicit config override win over the convention match', function () {
    Numerosis::resetModelCache();
    Config::set('numerosis.models.'.PackageTenant::class, 'App\\Models\\Central\\SomeOtherOverride');

    expect(Numerosis::model(PackageTenant::class))->toBe('App\\Models\\Central\\SomeOtherOverride');

    Config::set('numerosis.models.'.PackageTenant::class, null);
});

it('memoizes model() and resetModelCache() clears the memoized value', function () {
    Numerosis::resetModelCache();
    Config::set('numerosis.models.'.PackageTenant::class, 'App\\Models\\Central\\First');

    expect(Numerosis::model(PackageTenant::class))->toBe('App\\Models\\Central\\First');

    // Config changes; a cached call still reports the stale value.
    Config::set('numerosis.models.'.PackageTenant::class, 'App\\Models\\Central\\Second');
    expect(Numerosis::model(PackageTenant::class))->toBe('App\\Models\\Central\\First');

    // Clearing the cache picks up the new config.
    Numerosis::resetModelCache();
    expect(Numerosis::model(PackageTenant::class))->toBe('App\\Models\\Central\\Second');

    Config::set('numerosis.models.'.PackageTenant::class, null);
    Numerosis::resetModelCache();
});

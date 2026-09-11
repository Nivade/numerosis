<?php

declare(strict_types=1);

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Routing\RouteNames;

/*
 * Adopting numerosis into an app that already works must not delete what
 * already works: the host's own routes/web.php, routes/tenant.php and
 * routes/api.php all load through Numerosis::routes(), because
 * withRouting(using:) skips everything ApplicationBuilder would have built.
 */

function writeHostRoute(string $file, string $body): void
{
    File::ensureDirectoryExists(base_path('routes'));
    File::put(base_path('routes/'.$file), "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n".$body);
}

/**
 * `Route::getByName()` reads a lookup table `RouteServiceProvider` rebuilds
 * after loading a route file — a bypass the direct `Numerosis::routes()` calls
 * below never go through, so a freshly added route has to be found by scanning
 * the collection.
 *
 * @return Collection<int, IlluminateRoute>
 */
function registeredRoutesFor(string $uri): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (IlluminateRoute $route): bool => $route->uri() === $uri)
        ->values();
}

beforeEach(function (): void {
    // TestCase::defineRoutes() already ran Numerosis::routes() before the host
    // files below existed, and registration order is what these assert.
    resolve(Router::class)->setRoutes(new RouteCollection);
});

afterEach(function (): void {
    foreach (['web.php', 'tenant.php', 'api.php'] as $file) {
        File::delete(base_path('routes/'.$file));
    }
});

it('loads the host routes/web.php into every central domain group', function () {
    writeHostRoute('web.php', "Route::get('/host-probe', fn () => 'ok')->name('host.probe');\n");

    Numerosis::routes();

    $routes = registeredRoutesFor('host-probe');
    $centralDomains = Config::array('tenancy.central_domains');

    expect($routes)->toHaveCount(count($centralDomains))
        ->and($routes->first()?->getDomain())->toBe($centralDomains[0])
        ->and($routes->first()?->gatherMiddleware())->toContain('web');
});

/*
 * `RouteCollection::addToCollections()` keys on method + domain + URI, so two
 * routes on `/` do not coexist: the later registration replaces the earlier
 * one outright. The host file is loaded last for exactly that reason, and a
 * host naming its route `home` keeps `route('home')` resolving through
 * `RouteServiceProvider`'s name-lookup rebuild.
 */
it('gives the host route on / the path match', function () {
    writeHostRoute('web.php', "Route::get('/', fn () => 'host home')->name('".RouteNames::home()."');\n");

    Numerosis::routes();

    $centralDomains = Config::array('tenancy.central_domains');

    $root = registeredRoutesFor('/')
        ->sole(fn (IlluminateRoute $route): bool => $route->getDomain() === $centralDomains[0]);

    $action = $root->getAction('uses');

    expect($root->getName())->toBe(RouteNames::home())
        ->and(is_callable($action) ? app()->call($action) : null)->toBe('host home');
});

it('loads the host routes/tenant.php into the tenant group', function () {
    writeHostRoute('tenant.php', "Route::get('/host-tenant-probe', fn () => 'ok')->name('host.tenant.probe');\n");

    Numerosis::routes();

    expect(registeredRoutesFor('host-tenant-probe')->sole()->gatherMiddleware())->toContain('tenant');
});

it('loads the host routes/api.php under the api prefix and middleware', function () {
    writeHostRoute('api.php', "Route::get('/ping', fn () => 'pong')->name('host.api.ping');\n");

    Numerosis::routes();

    $route = registeredRoutesFor('api/ping')->sole();

    expect($route->gatherMiddleware())->toContain('api')
        ->and($route->getDomain())->toBeNull();
});

it('honours a non default api prefix', function () {
    writeHostRoute('api.php', "Route::get('/ping', fn () => 'pong')->name('host.api.ping');\n");

    Numerosis::routes(apiPrefix: 'v2');

    expect(registeredRoutesFor('v2/ping'))->toHaveCount(1);
});

it('registers the package own routes when the host has none of the three files', function () {
    Numerosis::routes();

    expect(registeredRoutesFor('/')->all())->not->toBeEmpty();
});

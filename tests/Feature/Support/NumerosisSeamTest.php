<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Http\Middleware\CheckInvitationStatus;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
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
    if (! $central instanceof IlluminateRoute) {
        throw new RuntimeException('route [terms] not registered');
    }

    expect($central->getDomain())->toBe(Config::array('tenancy.central_domains')[0]);
    expect($central->middleware())->toContain('web');

    $tenant = Route::getRoutes()->getByName('verification.notice');
    if (! $tenant instanceof IlluminateRoute) {
        throw new RuntimeException('route [verification.notice] not registered');
    }

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

    if (! $exceptions->captured instanceof Closure) {
        throw new RuntimeException('context callback was not captured');
    }

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

    if (! $exceptions->captured instanceof Closure) {
        throw new RuntimeException('context callback was not captured');
    }

    $callback = $exceptions->captured;

    $tenant->run(function () use ($callback, $tenant) {
        $context = $callback();

        expect($context['tenant_id'])->toBe((string) $tenant->getTenantKey());
    });
});

it('points assetSourcePaths(), tenantMigrationPath() and broadcastChannelsPath() at real directories', function () {
    foreach (Numerosis::assetSourcePaths() as $source => $target) {
        expect(is_dir($source))->toBeTrue("expected {$source} to exist");
    }

    expect(is_dir(Numerosis::tenantMigrationPath()))->toBeTrue();
    expect(file_exists(Numerosis::broadcastChannelsPath()))->toBeTrue();
});

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Providers;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Livewire;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Http\Middleware\InitializeTenancy;
use Nvade\Numerosis\Http\Middleware\NullMiddleware;
use Nvade\Numerosis\Http\Middleware\TenantRouteGuard;
use Nvade\Numerosis\Jobs\SeedTenantDatabase;
use Nvade\Numerosis\Listeners\Tenancy\LogSyncedResourceChangedInForeignDatabase;
use Nvade\Numerosis\Listeners\Tenancy\UpdateSyncedResource;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Services\Tenancy\PreservingPathTenantResolver;
use Override;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events\BootstrappingTenancy;
use Stancl\Tenancy\Events\CreatingDomain;
use Stancl\Tenancy\Events\CreatingTenant;
use Stancl\Tenancy\Events\DatabaseCreated;
use Stancl\Tenancy\Events\DatabaseDeleted;
use Stancl\Tenancy\Events\DatabaseMigrated;
use Stancl\Tenancy\Events\DatabaseRolledBack;
use Stancl\Tenancy\Events\DatabaseSeeded;
use Stancl\Tenancy\Events\DeletingDomain;
use Stancl\Tenancy\Events\DeletingTenant;
use Stancl\Tenancy\Events\DomainCreated;
use Stancl\Tenancy\Events\DomainDeleted;
use Stancl\Tenancy\Events\DomainSaved;
use Stancl\Tenancy\Events\DomainUpdated;
use Stancl\Tenancy\Events\EndingTenancy;
use Stancl\Tenancy\Events\InitializingTenancy;
use Stancl\Tenancy\Events\RevertedToCentralContext;
use Stancl\Tenancy\Events\RevertingToCentralContext;
use Stancl\Tenancy\Events\SavingDomain;
use Stancl\Tenancy\Events\SavingTenant;
use Stancl\Tenancy\Events\SyncedResourceChangedInForeignDatabase;
use Stancl\Tenancy\Events\SyncedResourceSaved;
use Stancl\Tenancy\Events\TenancyBootstrapped;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Events\TenantDeleted;
use Stancl\Tenancy\Events\TenantSaved;
use Stancl\Tenancy\Events\TenantUpdated;
use Stancl\Tenancy\Events\UpdatingDomain;
use Stancl\Tenancy\Events\UpdatingTenant;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\DeleteDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

class TenancyServiceProvider extends ServiceProvider
{
    // By default, no namespace is used to support the callable array syntax.
    public static string $controllerNamespace = '';

    /**
     * The middleware that identifies a tenant from the request, chosen by
     * {@see IdentificationMode::current()}.
     */
    public static function identificationMiddleware(): string
    {
        return match (IdentificationMode::current()) {
            IdentificationMode::Subdomain => \Nvade\Numerosis\Http\Middleware\InitializeTenancyByDomainOrSubdomain::class,
            IdentificationMode::CustomDomain => InitializeTenancyByDomain::class,
            IdentificationMode::Path => InitializeTenancyByPath::class,
        };
    }

    /**
     * The Livewire update route carries no `{tenant}` parameter, so
     * `InitializeTenancyByPath` cannot be applied to it. Every other mode
     * identifies by domain, which needs no route parameter.
     *
     * @see \Nvade\Numerosis\Http\Middleware\InitializeLivewireTenancyByPath
     */
    public static function livewireUpdateIdentificationMiddleware(): string
    {
        return IdentificationMode::current() === IdentificationMode::Path
            ? \Nvade\Numerosis\Http\Middleware\InitializeLivewireTenancyByPath::class
            : static::identificationMiddleware();
    }

    /**
     * The central-domain-block gate used inside the `tenant` middleware
     * group. Under `IdentificationMode::Path`, tenant routes deliberately
     * live on the central domain (path-prefixed), so the ordinary block
     * would 404 every tenant request.
     */
    public static function tenancyRouteMiddleware(): string
    {
        return IdentificationMode::current() === IdentificationMode::Path
            ? NullMiddleware::class
            : PreventAccessFromCentralDomains::class;
    }

    /**
     * The jobs that build a tenant's database, in order.
     *
     * Replace this only from a test bootstrap. Swapping migrate and seed for a
     * copy of a prepared template database is worth roughly ten times the
     * speed per tenant. Application code should leave it alone.
     *
     * @var list<class-string>
     */
    public static array $tenantCreatedJobs = [
        CreateDatabase::class,
        MigrateDatabase::class,
        SeedTenantDatabase::class,
    ];

    /**
     * @return array<class-string, array<int, class-string|JobPipeline>>
     */
    public function events(): array
    {
        return [
            // Tenant events
            CreatingTenant::class => [],
            TenantCreated::class => [
                JobPipeline::make(static::$tenantCreatedJobs)->send(fn (TenantCreated $event) => $event->tenant)
                    ->shouldBeQueued(true), // `false` by default, but you probably want to make this `true` for production.
            ],
            SavingTenant::class => [],
            TenantSaved::class => [],
            UpdatingTenant::class => [],
            TenantUpdated::class => [],
            DeletingTenant::class => [],
            TenantDeleted::class => [
                JobPipeline::make([
                    DeleteDatabase::class,
                ])->send(fn (TenantDeleted $event) => $event->tenant)->shouldBeQueued(true), // `false` by default, but you probably want to make this `true` for production.
            ],

            // Domain events
            CreatingDomain::class => [],
            DomainCreated::class => [],
            SavingDomain::class => [],
            DomainSaved::class => [],
            UpdatingDomain::class => [],
            DomainUpdated::class => [],
            DeletingDomain::class => [],
            DomainDeleted::class => [],

            // Database events
            DatabaseCreated::class => [],
            DatabaseMigrated::class => [],
            DatabaseSeeded::class => [],
            DatabaseRolledBack::class => [],
            DatabaseDeleted::class => [],

            // Tenancy events
            InitializingTenancy::class => [],
            TenancyInitialized::class => [
                BootstrapTenancy::class,
            ],

            EndingTenancy::class => [],
            TenancyEnded::class => [
                RevertToCentralContext::class,
            ],

            BootstrappingTenancy::class => [],
            TenancyBootstrapped::class => [],
            RevertingToCentralContext::class => [],
            RevertedToCentralContext::class => [],

            // Resource syncing
            SyncedResourceSaved::class => [
                UpdateSyncedResource::class,
            ],

            // Fired only when a synced resource is changed in a different DB than the origin DB (to avoid infinite loops)
            SyncedResourceChangedInForeignDatabase::class => [
                LogSyncedResourceChangedInForeignDatabase::class,
            ],
        ];
    }

    #[Override]
    public function register()
    {
        UpdateSyncedResource::$shouldQueue = true;

        /** @var array<class-string, class-string> $implementations */
        $implementations = config('numerosis.tenancy.implementations', []);

        foreach ($implementations as $contract => $concrete) {
            $this->app->bind($contract, $concrete);
        }

        $this->registerCachedDomainResolver();

        // See PreservingPathTenantResolver's docblock: only matters when
        // IdentificationMode::Path is selected and InitializeTenancyByPath
        // is actually used, harmless otherwise.
        $this->app->bind(PathTenantResolver::class, PreservingPathTenantResolver::class);
    }

    /**
     * Caches the domain-to-tenant lookup, invalidated whenever a tenant or
     * domain changes. The container's cache manager becomes tenant-scoped
     * inside tenant context, so a resolver built with it would write to one
     * namespace while invalidation cleared another and a domain change would
     * appear not to take effect; it gets a `new CacheManager($app)` instead.
     */
    protected function registerCachedDomainResolver(): void
    {
        // Deferred to booting(): the allowlist check reads `tenancy.tenant_model`,
        // which HostConfig::apply() fills in from an earlier-registered booting()
        // callback. Nothing reads the flag until a request resolves a domain.
        $this->app->booting(function (): void {
            DomainTenantResolver::$shouldCache = self::shouldCacheResolvedTenants();
        });

        $this->app->singleton(
            DomainTenantResolver::class,
            fn (Application $app) => new DomainTenantResolver(new CacheManager($app)),
        );
    }

    /**
     * `DomainTenantResolver` caches a whole tenant model, so the cache follows
     * what `cache.serializable_classes` can store: an allowlist has to name the
     * tenant model, `false` disables the cache, and
     * `numerosis.tenancy.cache_resolved_tenants` overrides either way. A store
     * that cannot unserialize it returns `__PHP_Incomplete_Class` silently.
     */
    public static function shouldCacheResolvedTenants(): bool
    {
        $configured = Config::get('numerosis.tenancy.cache_resolved_tenants');

        if (is_bool($configured)) {
            return $configured;
        }

        $serializableClasses = Config::get('cache.serializable_classes');

        // null is Laravel's "no restriction" value: the stores only pass
        // `allowed_classes` to unserialize() when this is non-null.
        if ($serializableClasses === null || $serializableClasses === true) {
            return true;
        }

        if (! is_array($serializableClasses)) {
            return false;
        }

        $tenantModel = Config::get('tenancy.tenant_model') ?? Numerosis::model(Tenant::class);

        return in_array($tenantModel, $serializableClasses, true);
    }

    public function boot(): void
    {
        $this->bootEvents();

        $this->makeTenancyMiddlewareHighestPriority();

        Livewire::setUpdateRoute(fn ($handle) => $this->app->make(Router::class)->post('/livewire/update', $handle)
            ->middleware(
                'web',
                'universal',
                static::livewireUpdateIdentificationMiddleware(),
                EnsureSessionMatchesTenant::class,
            ));
    }

    protected function bootEvents(): void
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                $listener = $listener instanceof JobPipeline ? $listener->toListener() : $listener;

                $this->app->make(Dispatcher::class)->listen($event, $listener);
            }
        }
    }

    protected function makeTenancyMiddlewareHighestPriority(): void
    {
        $tenancyMiddleware = [
            // Even higher priority than the initialization middleware. The
            // `tenancy.route` alias resolves to TenantRouteGuard, which
            // delegates to this at request time; both need to be listed here
            // since Laravel's priority sort runs against the alias's literal
            // target class, not what that class delegates to.
            TenantRouteGuard::class,
            PreventAccessFromCentralDomains::class,

            // The `tenancy.identification` alias resolves to InitializeTenancy,
            // which delegates to whichever of the classes below matches
            // IdentificationMode::current() — same reasoning as above.
            InitializeTenancy::class,
            InitializeTenancyByDomain::class,
            InitializeTenancyBySubdomain::class,
            InitializeTenancyByDomainOrSubdomain::class,
            \Nvade\Numerosis\Http\Middleware\InitializeTenancyByDomainOrSubdomain::class,
            InitializeTenancyByPath::class,
            InitializeTenancyByRequestData::class,
        ];

        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $this->app->make(Kernel::class)->prependToMiddlewarePriority($middleware);
        }
    }
}

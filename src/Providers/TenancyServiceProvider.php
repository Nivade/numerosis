<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Providers;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Livewire;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Jobs\SeedTenantDatabase;
use Nvade\Numerosis\Listeners\Tenancy\LogSyncedResourceChangedInForeignDatabase;
use Nvade\Numerosis\Listeners\Tenancy\UpdateSyncedResource;
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

class TenancyServiceProvider extends ServiceProvider
{
    // By default, no namespace is used to support the callable array syntax.
    public static string $controllerNamespace = '';

    public const TENANCY_IDENTIFICATION = \Nvade\Numerosis\Http\Middleware\InitializeTenancyByDomainOrSubdomain::class;

    /**
     * Jobs run, in order, when a tenant is created.
     *
     * Overridable so the test bootstrap can swap the migrate + seed pair for a
     * copy of a pre-built template database, which is the difference between
     * ~1.9s and ~0.18s per tenant. Nothing in the application should change it.
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
    }

    /**
     * Without this, every request to a tenant subdomain pays a
     * `whereHas('domains')` lookup (with `domains` eagerly loaded) against the
     * central database before anything else runs — a two-query floor on the
     * hot path for data that changes almost never. The cache is invalidated by
     * `InvalidatesResolverCache` on {@see \Nvade\Numerosis\Models\Central\Tenant} and
     * `InvalidatesTenantsResolverCache` on {@see \Nvade\Numerosis\Models\Central\Domain}.
     *
     * The binding exists because `CachedTenantResolver::__construct()` resolves
     * `cache` out of the container, and `CacheTenancyBootstrapper` swaps that
     * binding for Stancl's tenant-aware manager, which forwards everything
     * through `->tags(['tenant'.$key])`. A resolver constructed inside tenant
     * context (a queue worker under `QueueTenancyBootstrapper`, a console
     * command in tenant context) would therefore read and write a
     * tenant-prefixed namespace, while `invalidateCache()` called from central
     * context clears a different one — a domain change that intermittently
     * appears not to take effect. Handing it a concrete `CacheManager`, built
     * here and never swapped, pins the store to the central namespace.
     *
     * It is a singleton so that the resolver and both invalidation traits
     * (which resolve `DomainTenantResolver` from the container themselves)
     * share one repository instance. That also matters under
     * `CACHE_STORE=array`, where a second manager means a second, empty store.
     */
    protected function registerCachedDomainResolver(): void
    {
        DomainTenantResolver::$shouldCache = true;

        $this->app->singleton(
            DomainTenantResolver::class,
            fn (Application $app) => new DomainTenantResolver(new CacheManager($app)),
        );
    }

    public function boot(): void
    {
        $this->bootEvents();
        //        $this->mapRoutes();

        $this->makeTenancyMiddlewareHighestPriority();

        Livewire::setUpdateRoute(fn ($handle) => $this->app->make(Router::class)->post('/livewire/update', $handle)
            ->middleware(
                'web',
                'universal',
                static::TENANCY_IDENTIFICATION,
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
            // Even higher priority than the initialization middleware
            PreventAccessFromCentralDomains::class,

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

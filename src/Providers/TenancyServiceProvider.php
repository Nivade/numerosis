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
use Nvade\Numerosis\Boot\TenancyRouting;
use Nvade\Numerosis\Contracts\Notifications\NotificationChannels;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseDumper;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Http\Middleware\InitializeTenancy;
use Nvade\Numerosis\Http\Middleware\InitializeTenancyByTenantDomain;
use Nvade\Numerosis\Http\Middleware\TenantRouteGuard;
use Nvade\Numerosis\Listeners\Tenancy\LogSyncedResourceChangedInForeignDatabase;
use Nvade\Numerosis\Listeners\Tenancy\UpdateSyncedResource;
use Nvade\Numerosis\Services\Notifications\PreferredNotificationChannels;
use Nvade\Numerosis\Services\Tenancy\PortableTenantDatabaseDumper;
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
use Stancl\Tenancy\Jobs\DeleteDatabase;
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
     * @return array<class-string, array<int, class-string|JobPipeline>>
     */
    public function events(): array
    {
        return [
            // Tenant events
            CreatingTenant::class => [],
            // Deliberately empty: building a tenant's database is a step in
            // `numerosis.tenancy.provisioning.steps`, so creating the row has
            // no database as a side effect.
            TenantCreated::class => [],
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

        // Scoped: the channel resolver memoizes one preference row per
        // recipient, and a fan-out re-reads it for every member otherwise.
        $this->app->scoped(
            NotificationChannels::class,
            $implementations[NotificationChannels::class] ?? PreferredNotificationChannels::class,
        );

        $this->registerDatabaseDumper();

        $this->registerCachedDomainResolver();
        $this->registerCachedPathResolver();
    }

    /**
     * Which dumper a backup uses is a question about the tenant connection's
     * driver, so it is resolved per call instead of bound to one class.
     */
    protected function registerDatabaseDumper(): void
    {
        $this->app->bind(function (Application $app): TenantDatabaseDumper {
            /** @var array<string, class-string<TenantDatabaseDumper>> $dumpers */
            $dumpers = config('numerosis.tenancy.backup.dumpers', []);

            $driver = config()->string('database.connections.tenant.driver', 'mysql');

            return $app->make($dumpers[$driver] ?? PortableTenantDatabaseDumper::class);
        });
    }

    /**
     * The path resolver's cache flag is a separate static on a separate class
     * from the domain resolver's, so path mode ran a central lookup per
     * request until this mirrored {@see self::registerCachedDomainResolver()},
     * `new CacheManager($app)` and singleton included.
     */
    protected function registerCachedPathResolver(): void
    {
        $this->app->booting(function (): void {
            PreservingPathTenantResolver::$shouldCache = TenancyRouting::shouldCacheResolvedTenants();
        });

        $this->app->singleton(
            PreservingPathTenantResolver::class,
            fn (Application $app) => new PreservingPathTenantResolver(new CacheManager($app)),
        );

        $this->app->alias(PreservingPathTenantResolver::class, PathTenantResolver::class);
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
            DomainTenantResolver::$shouldCache = TenancyRouting::shouldCacheResolvedTenants();
        });

        $this->app->singleton(
            DomainTenantResolver::class,
            fn (Application $app) => new DomainTenantResolver(new CacheManager($app)),
        );
    }

    public function boot(): void
    {
        $this->bootEvents();

        $this->makeTenancyMiddlewareHighestPriority();

        Livewire::setUpdateRoute(fn ($handle) => $this->app->make(Router::class)->post('/livewire/update', $handle)
            ->middleware(
                'web',
                'universal',
                TenancyRouting::livewireUpdateIdentificationMiddleware(),
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
            // Both the guard and what it delegates to: Laravel's priority
            // sort runs against the alias's literal target class instead of
            // against what that class delegates to at request time.
            TenantRouteGuard::class,
            PreventAccessFromCentralDomains::class,

            // The `tenancy.identification` alias resolves to InitializeTenancy,
            // which delegates to whichever of the classes below matches
            // IdentificationMode::current(), the same reasoning as above.
            InitializeTenancy::class,
            InitializeTenancyByDomain::class,
            InitializeTenancyBySubdomain::class,
            InitializeTenancyByDomainOrSubdomain::class,
            InitializeTenancyByTenantDomain::class,
            InitializeTenancyByPath::class,
            InitializeTenancyByRequestData::class,
        ];

        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $this->app->make(Kernel::class)->prependToMiddlewarePriority($middleware);
        }
    }
}

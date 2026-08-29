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
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Jobs\SeedTenantDatabase;
use Nvade\Numerosis\Listeners\Tenancy\LogSyncedResourceChangedInForeignDatabase;
use Nvade\Numerosis\Listeners\Tenancy\UpdateSyncedResource;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\Numerosis\Support\Tenancy\TenancyConfigKeys;
use Nvade\Numerosis\Support\Tenancy\TenancyVersion;
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
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

class TenancyServiceProvider extends ServiceProvider
{
    // By default, no namespace is used to support the callable array syntax.
    public static string $controllerNamespace = '';

    public const TENANCY_IDENTIFICATION = \Nvade\Numerosis\Http\Middleware\InitializeTenancyByDomainOrSubdomain::class;

    /**
     * The jobs that build a tenant's database, in order.
     *
     * Replace this only from a test bootstrap — swapping migrate and seed for
     * a copy of a prepared template database is worth roughly ten times the
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
            TenancyVersion::syncedResourceSavedEventClass() => [
                UpdateSyncedResource::class,
            ],

            // Fired only when a synced resource is changed in a different DB than the origin DB (to avoid infinite loops)
            TenancyVersion::syncedResourceChangedInForeignDatabaseEventClass() => [
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
     * Caches the domain-to-tenant lookup, which every tenant request would
     * otherwise pay against the central database before anything else runs.
     * Invalidated whenever a tenant or domain changes.
     *
     * Given a cache manager of its own rather than the container's, which
     * becomes tenant-scoped inside tenant context — a resolver built there
     * would write to one namespace while invalidation cleared another, so a
     * domain change would appear not to take effect. Bound as a singleton so
     * the resolver and its invalidators share one store.
     */
    protected function registerCachedDomainResolver(): void
    {
        // Decided from a booting() callback rather than here: the allowlist
        // check reads `tenancy.tenant_model`, which HostConfig::apply() fills
        // in from its own booting() callback — registered earlier, so it runs
        // first. Nothing reads the flag until a request resolves a domain.
        $this->app->booting(function (): void {
            DomainTenantResolver::$shouldCache = self::shouldCacheResolvedTenants();
        });

        $this->app->singleton(
            DomainTenantResolver::class,
            fn (Application $app) => new DomainTenantResolver(new CacheManager($app)),
        );
    }

    /**
     * Whether the resolver's tenant cache can be trusted on this host.
     *
     * `DomainTenantResolver` caches a whole tenant *model*, and Laravel's own
     * `cache.serializable_classes` decides whether any cache store may
     * `unserialize()` an object at all. A fresh Laravel app ships `false`
     * there — hardening against gadget chains — which does not make the read
     * fail: it silently returns `__PHP_Incomplete_Class` instead of the
     * object, with no exception and no log line. The first request after a
     * cache clear then resolves fine (cache miss) and every request after it
     * dies on `DomainTenantResolver::resolved(): Argument #1 ($tenant) must be
     * of type Tenant, __PHP_Incomplete_Class given`, which reads like a
     * tenancy bug and is two config defaults disagreeing.
     *
     * So the cache follows what the host's cache config can actually store: an
     * allowlist has to name the tenant model, `false` disables the cache, and
     * `numerosis.tenancy.cache_resolved_tenants` overrides the lot in either
     * direction. `numerosis:install`'s `verifyTenantResolverCache()` reports
     * when this has turned the cache off, since losing it costs a central
     * lookup per tenant request.
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

        $tenantModel = Config::get(TenancyConfigKeys::key('tenant_model')) ?? Numerosis::model(Tenant::class);

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
            TenancyVersion::preventAccessFromCentralDomainsMiddleware(),

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

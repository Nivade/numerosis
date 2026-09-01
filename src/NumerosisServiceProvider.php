<?php

declare(strict_types=1);

namespace Nvade\Numerosis;

use Closure;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Assets\Theme;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use InterNACHI\Modular\Support\Facades\Modules;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Auth\AuthenticateLoginCandidate;
use Nvade\Numerosis\Actions\Auth\CreateRegisteredUser;
use Nvade\Numerosis\Actions\Auth\ResolveLoginCandidate;
use Nvade\Numerosis\Actions\Auth\ResolvePostLoginRedirectUrl;
use Nvade\Numerosis\Actions\Auth\SendEmailVerificationNotification;
use Nvade\Numerosis\Actions\Invitations\CreateInvitedUser;
use Nvade\Numerosis\Commands\InstallNumerosisCommand;
use Nvade\Numerosis\Concerns\PublishesPackageAssets;
use Nvade\Numerosis\Console\Commands\DeleteTenants;
use Nvade\Numerosis\Console\Commands\MigrateTenantModule;
use Nvade\Numerosis\Console\Commands\PruneOrphanedStripeCustomers;
use Nvade\Numerosis\Console\Commands\PruneOrphanedTenantDatabases;
use Nvade\Numerosis\Console\Commands\PruneStalledTenantProvisions;
use Nvade\Numerosis\Console\Commands\RollbackTenantModule;
use Nvade\Numerosis\Console\Commands\SeedTenantModule;
use Nvade\Numerosis\Contracts\Auth\AuthenticatesLoginCandidate;
use Nvade\Numerosis\Contracts\Auth\CreatesRegisteredUser;
use Nvade\Numerosis\Contracts\Auth\ResolvesLoginCandidate;
use Nvade\Numerosis\Contracts\Auth\ResolvesPostLoginRedirectUrl;
use Nvade\Numerosis\Contracts\Auth\SendsEmailVerificationNotification;
use Nvade\Numerosis\Contracts\Auth\SocialAccountRepository;
use Nvade\Numerosis\Contracts\Invitations\CreatesInvitedUser;
use Nvade\Numerosis\Contracts\Invitations\InvitationRepository;
use Nvade\Numerosis\Contracts\Notifications\NotifiesTenantOwner;
use Nvade\Numerosis\Database\Seeders\DatabaseSeeder as PackageDatabaseSeeder;
use Nvade\Numerosis\Events\Auth\SocialAccountConnected;
use Nvade\Numerosis\Events\Auth\SocialAccountDisconnected;
use Nvade\Numerosis\Events\Billing\PaymentFailed;
use Nvade\Numerosis\Events\Billing\PaymentSettled;
use Nvade\Numerosis\Events\Billing\TenantSuspended;
use Nvade\Numerosis\Events\Invitations\InvitationIssued;
use Nvade\Numerosis\Events\Modules\ModulePurchased;
use Nvade\Numerosis\Http\Middleware\CheckInvitationStatus;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Listeners\Auth\LogSocialAccountConnected;
use Nvade\Numerosis\Listeners\Auth\LogSocialAccountDisconnected;
use Nvade\Numerosis\Listeners\Billing\SendPaymentConfirmedNotification;
use Nvade\Numerosis\Listeners\Billing\SendPaymentFailedNotification;
use Nvade\Numerosis\Listeners\Billing\SendTenantSuspendedNotification;
use Nvade\Numerosis\Listeners\Invitations\SendInvitationNotification;
use Nvade\Numerosis\Listeners\Modules\QueueModuleMigration;
use Nvade\Numerosis\Livewire\Billing\Checkout;
use Nvade\Numerosis\Providers\BillingServiceProvider;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Nvade\Numerosis\Services\Auth\EloquentSocialAccountRepository;
use Nvade\Numerosis\Services\Invitations\EloquentInvitationRepository;
use Nvade\Numerosis\Services\Notifications\NotifiesTenantOwnerDirectly;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\HostConfig;
use Nvade\Numerosis\Support\Numerosis;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class NumerosisServiceProvider extends PackageServiceProvider
{
    use PublishesPackageAssets;

    /** Filament theme asset id, applied by both panel plugins. */
    public const string THEME_ID = 'numerosis-filament-theme';

    /** Asset id for the package's own JS and CSS bundles. */
    public const string ASSET_ID = 'numerosis';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('numerosis')
            ->hasViews()
            ->hasTranslations()
            ->discoversMigrations(true, '/database/migrations/central')
            ->runsMigrations()
            ->hasCommand(InstallNumerosisCommand::class)
            ->hasCommand(DeleteTenants::class)
            ->hasCommands($this->moduleCommands())
            ->hasCommand(PruneOrphanedStripeCustomers::class)
            ->hasCommand(PruneOrphanedTenantDatabases::class)
            ->hasCommand(PruneStalledTenantProvisions::class);
    }

    /**
     * The `tenants:*-module` commands, which exist only when the module
     * registry does.
     *
     * Gated on `internachi/modular` being installed rather than on
     * `ModuleSystemFeature`: configurePackage() runs before this package's own
     * `mergeConfigFrom()`, so `numerosis.features` is not readable yet and a
     * feature check here would drop the commands even for a host that wants
     * them. The feature switch is enforced inside each command instead, via
     * Concerns\ResolvesInstalledModules.
     *
     * @return list<class-string>
     */
    private function moduleCommands(): array
    {
        if (! class_exists(Modules::class)) {
            return [];
        }

        return [
            MigrateTenantModule::class,
            RollbackTenantModule::class,
            SeedTenantModule::class,
        ];
    }

    public function packageRegistered(): void
    {
        // Done here rather than through `hasConfigFile('numerosis')`, which
        // would also register the package's own config file for publishing —
        // and that file must never be published: it assembles the fifteen
        // partials in config/numerosis/ by `require __DIR__`, which would
        // resolve against a host's config directory. The publishable copy is
        // the override stub registered in packageBooted(). Same phase
        // package-tools would have merged in (register(), right before
        // packageRegistered()), so nothing below sees a different config.
        $this->mergeConfigFrom(__DIR__.'/../config/numerosis.php', 'numerosis');

        Numerosis::resetModelCache();

        // Deferred until every provider has registered, so config shipped by
        // stancl/tenancy and Laravel itself is normalized after it is merged
        // rather than before.
        $this->app->booting(function (): void {
            HostConfig::apply();
        });

        $this->app->register(TenancyServiceProvider::class);
        $this->app->register(BillingServiceProvider::class);

        $this->app->bind(ResolvesLoginCandidate::class, ResolveLoginCandidate::class);
        $this->app->bind(AuthenticatesLoginCandidate::class, AuthenticateLoginCandidate::class);
        $this->app->bind(ResolvesPostLoginRedirectUrl::class, ResolvePostLoginRedirectUrl::class);
        $this->app->bind(CreatesRegisteredUser::class, CreateRegisteredUser::class);
        $this->app->bind(SendsEmailVerificationNotification::class, SendEmailVerificationNotification::class);
        $this->app->bind(CreatesInvitedUser::class, CreateInvitedUser::class);
        $this->app->bind(InvitationRepository::class, EloquentInvitationRepository::class);
        $this->app->bind(SocialAccountRepository::class, EloquentSocialAccountRepository::class);
        $this->app->bind(NotifiesTenantOwner::class, NotifiesTenantOwnerDirectly::class);

        // `db:seed` resolves this class by name, so an app without one of its
        // own would never run the package's seeders. Define the class and
        // this binding steps aside.
        if (! class_exists('Database\Seeders\DatabaseSeeder')) {
            $this->app->bind('Database\Seeders\DatabaseSeeder', PackageDatabaseSeeder::class);
        }

        // Points the `layouts::` and `pages::` Livewire namespaces at the
        // views this package ships. Set either yourself to use your own —
        // from your own provider's register(), not boot(): Livewire reads this
        // config once in its own boot() and bakes the result into the view
        // finder's hints, so a later config change is accepted and has no
        // effect on how views actually resolve. App providers register after
        // package providers, so a register()-phase override wins here (this
        // loop only writes an unset or still-stock value) while a boot()-phase
        // one silently loses. See docs/host-requirements.md.
        foreach (['layouts', 'pages'] as $namespace) {
            $current = Config::get("livewire.component_namespaces.{$namespace}");

            if ($current === null || $current === resource_path("views/{$namespace}")) {
                Config::set("livewire.component_namespaces.{$namespace}", __DIR__."/../resources/views/{$namespace}");
            }
        }

        // Gives Livewire's temporary uploads a disk whose root never moves.
        // Its upload route runs outside tenancy, so a tenant-suffixed disk
        // would write and validate the same file in different directories.
        if (Config::get('filesystems.disks.livewire') === null) {
            Config::set('filesystems.disks.livewire', [
                'driver' => 'local',
                'root' => storage_path('app/private'),
                'throw' => false,
                'report' => false,
            ]);
        }

        if (Config::get('livewire.temporary_file_upload.disk') === null) {
            Config::set('livewire.temporary_file_upload.disk', 'livewire');
        }

        $this->registerHostPanelProviders();
    }

    /**
     * Registers a host's own panel providers, named in
     * `numerosis.panels.{admin,tenant}.provider`.
     *
     * The package's own defaults are no longer registered from here:
     * nvade/numerosis-filament owns both panels and registers them itself
     * when installed. A host naming a provider here is trusted to have
     * Filament, so the class is registered unguarded — this key is the
     * escape hatch for replacing a package panel wholesale, and a null
     * value simply means "whatever numerosis-filament registers, or
     * nothing at all".
     */
    protected function registerHostPanelProviders(): void
    {
        $this->app->booting(function (): void {
            foreach (['admin', 'tenant'] as $panel) {
                $provider = Config::get("numerosis.panels.{$panel}.provider");

                if (is_string($provider)) {
                    $this->app->register($provider);
                }
            }
        });
    }

    public function packageBooted(): void
    {
        Config::set('numerosis.views.path', __DIR__.'/../resources/views');

        // Factories ship with this package even when the model is a subclass
        // in your app namespace, so both directions of Laravel's default
        // model/factory guessing need replacing.
        Factory::guessFactoryNamesUsing(Numerosis::factoryNameFor(...));
        Factory::guessModelNamesUsing(fn (Factory $factory): string => Numerosis::modelNameFor($factory::class));

        foreach (Features::all() as $feature) {
            if (! class_exists($feature)) {
                Log::warning("Numerosis: configured feature [{$feature}] does not exist; skipping.");

                continue;
            }

            $this->app->make($feature)->bootstrap();
        }

        $this->registerRoutesFallback();

        $this->registerRequestMacros();

        $this->registerEventListeners();

        $this->registerSchedule();

        $this->registerMiddleware();

        $this->registerFilamentTheme();

        $this->registerBroadcasting();

        $this->registerExceptionHandling();

        // Components addressed by dotted name from this package's own Blade
        // views. Livewire cannot discover package classes on its own.
        // `settings.delete-user-form` is registered by nvade/numerosis-account,
        // which owns that component now.
        Livewire::addComponent(name: 'billing.checkout', class: Checkout::class);

        // A small override file, not a copy of the package's own config: the
        // deep-fill in HostConfig backfills every key it omits, and the
        // package file itself cannot be published (it requires its partials
        // by __DIR__). Same tag package-tools would have used.
        $this->publishGroup([
            __DIR__.'/../config/stubs/numerosis.php' => config_path('numerosis.php'),
        ], 'numerosis-config');

        // Tenant migrations run per-tenant, never centrally. Publish them
        // only to customize one; tenancy config points at the package copy.
        $this->publishGroup([
            __DIR__.'/../database/migrations/tenant' => database_path('migrations/tenant'),
        ], 'numerosis-tenant-migrations');

        // Publishes resources/js/numerosis.js for editing. Add it to your
        // Vite config and your build replaces the prebuilt bundle.
        $this->publishGroup(Numerosis::assetSourcePaths(), 'numerosis-assets');

        // Optional subclasses of the package's models, for apps that want to
        // extend them. Every package model is concrete and usable as-is.
        $modelStubs = [
            __DIR__.'/../stubs/Models/Central/Tenant.stub' => app_path('Models/Central/Tenant.php'),
            __DIR__.'/../stubs/Models/Central/Domain.stub' => app_path('Models/Central/Domain.php'),
            __DIR__.'/../stubs/Models/Central/CentralUser.stub' => app_path('Models/Central/CentralUser.php'),
            __DIR__.'/../stubs/Models/Central/Subscription.stub' => app_path('Models/Central/Subscription.php'),
            __DIR__.'/../stubs/Models/Central/PaymentPlan.stub' => app_path('Models/Central/PaymentPlan.php'),
            __DIR__.'/../stubs/Models/Central/PendingTenantProvision.stub' => app_path('Models/Central/PendingTenantProvision.php'),
            __DIR__.'/../stubs/Models/Tenant/User.stub' => app_path('Models/Tenant/User.php'),
            __DIR__.'/../stubs/Models/Tenant/Invitation.stub' => app_path('Models/Tenant/Invitation.php'),
            __DIR__.'/../stubs/Models/Tenant/Module.stub' => app_path('Models/Tenant/Module.php'),
        ];

        $this->publishGroup($modelStubs, 'numerosis-models');
    }

    /**
     * Registers the package's routes for an app whose `bootstrap/app.php`
     * never did. A no-op otherwise, and still captured by `route:cache`
     * either way.
     */
    protected function registerRoutesFallback(): void
    {
        $this->app->booted(function (): void {
            if (! Numerosis::routesRegistered()) {
                Numerosis::routes();
            }
        });
    }

    /**
     * Adds `request()->isCentralDomain()`, unless you have defined a macro
     * of that name yourself.
     */
    protected function registerRequestMacros(): void
    {
        if (Request::hasMacro('isCentralDomain')) {
            return;
        }

        Request::macro('isCentralDomain', function (): bool {
            /** @var Request $this */
            return Numerosis::isCentralDomain($this);
        });
    }

    /**
     * The package's scheduled commands. Toggle each through
     * `numerosis.schedule.*`; `telescope:prune` follows whether Telescope
     * is installed.
     */
    protected function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (class_exists('Laravel\Telescope\Telescope')) {
                $schedule->command('telescope:prune')->daily();
            }

            if (Config::boolean('numerosis.schedule.prune_orphaned_customers')) {
                $schedule->command('billing:prune-orphaned-customers')->daily();
            }

            if (Config::boolean('numerosis.schedule.prune_stalled_provisions')) {
                $schedule->command('tenancy:prune-stalled-provisions')->hourly();
            }

            // torann/geoip's MaxMind database driver (backs ResolveCheckoutRegion)
            // needs its local .mmdb file refreshed periodically — MaxMind
            // rotates license keys and update cadence. Only registered when
            // that service is actually configured, since geoip:update no-ops
            // (with a log line, not an error) for every other driver.
            if (Config::string('geoip.service') === 'maxmind_database') {
                $schedule->command('geoip:update')->weekly();
            }
        });
    }

    /**
     * The package's event listeners, which Laravel's own event discovery
     * does not scan for. Each checks its feature flag when handling an
     * event, so all are registered regardless of which features are on.
     *
     * Billing and tenancy listeners are registered by their own providers.
     */
    protected function registerEventListeners(): void
    {
        $listeners = [
            SocialAccountConnected::class => LogSocialAccountConnected::class,
            SocialAccountDisconnected::class => LogSocialAccountDisconnected::class,
            PaymentSettled::class => SendPaymentConfirmedNotification::class,
            PaymentFailed::class => SendPaymentFailedNotification::class,
            TenantSuspended::class => SendTenantSuspendedNotification::class,
            InvitationIssued::class => SendInvitationNotification::class,
            ModulePurchased::class => QueueModuleMigration::class,
        ];

        foreach ($listeners as $event => $listener) {
            Event::listen($event, $listener);
        }
    }

    /**
     * Registers the same aliases, groups and trust settings as
     * {@see Numerosis::middleware()}, for an app that never calls it from
     * `bootstrap/app.php`. {@see Numerosis::registerMiddlewareUsing()}
     * replaces this entirely.
     */
    protected function registerMiddleware(): void
    {
        if (Numerosis::$registerMiddlewareCallback instanceof Closure) {
            (Numerosis::$registerMiddlewareCallback)($this->app);

            return;
        }

        $this->seedMiddlewareBaselineIfMissing();

        Route::aliasMiddleware('invitation.status', CheckInvitationStatus::class);
        Route::aliasMiddleware('tenancy.identification', TenancyServiceProvider::identificationMiddleware());
        Route::aliasMiddleware('tenancy.route', TenancyServiceProvider::tenancyRouteMiddleware());
        Route::aliasMiddleware('tenancy.session', EnsureSessionMatchesTenant::class);

        Route::middlewareGroup('tenant', [
            'web',
            'tenancy.identification',
            'tenancy.route',
            'tenancy.session',
        ]);
        Route::middlewareGroup('universal', []);

        TrustProxies::at('*');

        $this->app->make(Kernel::class)->prependMiddleware(TrustHosts::class);

    }

    /**
     * Seeds Laravel's baseline `web` and `api` middleware groups for an app
     * whose `bootstrap/app.php` never calls `withMiddleware()`. Without
     * them the package's `tenant` group, which begins with `web`, cannot
     * resolve.
     */
    protected function seedMiddlewareBaselineIfMissing(): void
    {
        $kernel = $this->app->make(Kernel::class);

        if ($kernel->getMiddlewareGroups() !== []) {
            return;
        }

        $middleware = new Middleware;

        $kernel->setGlobalMiddleware($middleware->getGlobalMiddleware());
        $kernel->setMiddlewareGroups($middleware->getMiddlewareGroups());
        $kernel->setMiddlewareAliases($middleware->getMiddlewareAliases());

        $priorities = $middleware->getMiddlewarePriority();

        if ($priorities !== []) {
            $kernel->setMiddlewarePriority($priorities);
        }
    }

    /**
     * Registers the prebuilt Filament theme, JS and CSS as Filament assets,
     * served by the `filament:assets` command you already run. No Vite step
     * of your own is needed.
     */
    protected function registerFilamentTheme(): void
    {
        if (! class_exists(FilamentAsset::class)) {
            return;
        }

        FilamentAsset::register([
            Theme::make(self::THEME_ID, __DIR__.'/../dist/filament-theme.css'),
            Js::make(self::ASSET_ID, __DIR__.'/../dist/numerosis.js'),
            Css::make(self::ASSET_ID, __DIR__.'/../dist/numerosis.css'),
        ], package: 'nvade/numerosis');
    }

    /**
     * Registers `/broadcasting/auth` and the package's channels, so calling
     * `withBroadcasting()` yourself is optional — do both and the route is
     * still only registered once. {@see Numerosis::registerBroadcastingUsing()}
     * replaces this entirely.
     */
    protected function registerBroadcasting(): void
    {
        if (Numerosis::$registerBroadcastingCallback instanceof Closure) {
            (Numerosis::$registerBroadcastingCallback)($this->app);

            return;
        }
        $alreadyRegistered = collect(Route::getRoutes()->getRoutes())
            ->contains(fn ($route): bool => $route->uri() === 'broadcasting/auth');

        if (! $alreadyRegistered) {
            Broadcast::routes(['middleware' => Numerosis::broadcasting()]);
        }

        // require, not require_once: channels are re-registered on every
        // application boot within a process.
        require Numerosis::broadcastChannelsPath();
    }

    /**
     * Applies {@see Numerosis::exceptions()} for an app that never calls it
     * from `bootstrap/app.php` — including one that never calls
     * `->withExceptions()` at all, which leaves `ExceptionHandler::class`
     * unbound (that binding is normally made by `ApplicationBuilder::
     * withExceptions()` itself, the one framework call this self-heal can't
     * assume happened). Skipped if you have replaced Laravel's exception
     * handler with one of your own. {@see Numerosis::exceptions()} is
     * idempotent, so this runs unconditionally without double-registering
     * against a host that also calls it from its own bootstrap file.
     */
    protected function registerExceptionHandling(): void
    {
        if (! $this->app->bound(ExceptionHandler::class)) {
            $this->app->singleton(ExceptionHandler::class, Handler::class);
        }

        $handler = $this->app->make(ExceptionHandler::class);

        if ($handler instanceof Handler) {
            Numerosis::exceptions(new Exceptions($handler));
        }
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis;

use Closure;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\LoginResponse as FortifyLoginResponse;
use Laravel\Fortify\Contracts\LogoutResponse as FortifyLogoutResponse;
use Laravel\Fortify\Contracts\VerifyEmailResponse as FortifyVerifyEmailResponse;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Features as FortifyFeatures;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\LoginRequest as FortifyLoginRequest;
use Laravel\Fortify\Http\Requests\VerifyEmailRequest as FortifyVerifyEmailRequest;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Auth\CreateRegisteredUser;
use Nvade\Numerosis\Actions\Auth\LogInToCentralGuard;
use Nvade\Numerosis\Actions\Auth\RedirectIfOneTimePasswordAuthenticatable;
use Nvade\Numerosis\Actions\Auth\ResetUserPassword;
use Nvade\Numerosis\Actions\Auth\UpdateUserPassword;
use Nvade\Numerosis\Actions\Auth\UpdateUserProfile;
use Nvade\Numerosis\Boot\Assets;
use Nvade\Numerosis\Boot\ConfiguredSteps;
use Nvade\Numerosis\Boot\HostConfig;
use Nvade\Numerosis\Boot\MiddlewareRegistrar;
use Nvade\Numerosis\Boot\PlanMetadata;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Concerns\PublishesPackageAssets;
use Nvade\Numerosis\Console\Commands\BackupTenantCommand;
use Nvade\Numerosis\Console\Commands\DeleteTenants;
use Nvade\Numerosis\Console\Commands\EndStaleImpersonations;
use Nvade\Numerosis\Console\Commands\InstallNumerosisCommand;
use Nvade\Numerosis\Console\Commands\ListClosedTenants;
use Nvade\Numerosis\Console\Commands\MigrateTenants;
use Nvade\Numerosis\Console\Commands\ProvisionTenantCommand;
use Nvade\Numerosis\Console\Commands\PruneActivityLog;
use Nvade\Numerosis\Console\Commands\PruneDataExports;
use Nvade\Numerosis\Console\Commands\PruneNotifications;
use Nvade\Numerosis\Console\Commands\PruneOrphanedStripeCustomers;
use Nvade\Numerosis\Console\Commands\PruneOrphanedTenantDatabases;
use Nvade\Numerosis\Console\Commands\PruneSessions;
use Nvade\Numerosis\Console\Commands\PruneStalledTenantProvisions;
use Nvade\Numerosis\Console\Commands\PruneTenantBackups;
use Nvade\Numerosis\Console\Commands\ReconcileUsage;
use Nvade\Numerosis\Console\Commands\ReopenTenantCommand;
use Nvade\Numerosis\Console\Commands\ReportUsage;
use Nvade\Numerosis\Console\Commands\RestoreTenantCommand;
use Nvade\Numerosis\Console\Commands\TransferTenantOwnershipCommand;
use Nvade\Numerosis\Console\Commands\VerifyDomains;
use Nvade\Numerosis\Contracts\Billing\Entitlements;
use Nvade\Numerosis\Contracts\Exceptions\ProvidesExceptionContext;
use Nvade\Numerosis\Database\Seeders\DatabaseSeeder as PackageDatabaseSeeder;
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Events\Auth\PasswordChanged;
use Nvade\Numerosis\Events\Auth\SocialAccountLinked;
use Nvade\Numerosis\Events\Auth\SocialAccountUnlinked;
use Nvade\Numerosis\Events\Auth\SuspiciousLoginDetected;
use Nvade\Numerosis\Events\Billing\PaymentFailed;
use Nvade\Numerosis\Events\Billing\PaymentSettled;
use Nvade\Numerosis\Events\Billing\TenantSuspended;
use Nvade\Numerosis\Events\Invitations\InvitationCreated;
use Nvade\Numerosis\Events\Tenancy\MemberRemoved;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioned;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningFailed;
use Nvade\Numerosis\Events\Tenancy\TenantRestored;
use Nvade\Numerosis\Features\Auth\OneTimePasswordFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Http\Middleware\RequirePasswordIfSet;
use Nvade\Numerosis\Http\Requests\Auth\NumerosisLoginRequest;
use Nvade\Numerosis\Http\Requests\Auth\NumerosisVerifyEmailRequest;
use Nvade\Numerosis\Http\Responses\Auth\NumerosisLoginResponse;
use Nvade\Numerosis\Http\Responses\Auth\NumerosisLogoutResponse;
use Nvade\Numerosis\Http\Responses\Auth\NumerosisVerifyEmailResponse;
use Nvade\Numerosis\Listeners\Admin\SuppressMailWhileImpersonating;
use Nvade\Numerosis\Listeners\Audit\RecordDomainEventActivity;
use Nvade\Numerosis\Listeners\Auth\EndOtherGuardSession;
use Nvade\Numerosis\Listeners\Auth\LogSocialAccountLinked;
use Nvade\Numerosis\Listeners\Auth\LogSocialAccountUnlinked;
use Nvade\Numerosis\Listeners\Auth\RevokeSessionsAfterPasswordChange;
use Nvade\Numerosis\Listeners\Auth\RevokeSessionsAfterTwoFactorDisabled;
use Nvade\Numerosis\Listeners\Billing\SendPaymentConfirmedNotification;
use Nvade\Numerosis\Listeners\Billing\SendPaymentFailedNotification;
use Nvade\Numerosis\Listeners\Billing\SendTenantSuspendedNotification;
use Nvade\Numerosis\Listeners\Invitations\SendInvitationNotification;
use Nvade\Numerosis\Listeners\Tenancy\BackfillTenantUsers;
use Nvade\Numerosis\Listeners\Tenancy\EndSessionsForRemovedMember;
use Nvade\Numerosis\Listeners\Tenancy\ForgetTenantColumnListing;
use Nvade\Numerosis\Listeners\Tenancy\RevokeApiTokensForRemovedMember;
use Nvade\Numerosis\Listeners\Tenancy\SendProvisioningFailedAlert;
use Nvade\Numerosis\Listeners\Tenancy\SendTenantRestoredNotification;
use Nvade\Numerosis\Livewire\Billing\Checkout;
use Nvade\Numerosis\Livewire\Notifications\Center as NotificationCenter;
use Nvade\Numerosis\Livewire\Settings\ConnectedAccounts;
use Nvade\Numerosis\Livewire\Settings\DeleteUserForm;
use Nvade\Numerosis\Models\Central;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\OwnershipNomination;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\PlanFeature;
use Nvade\Numerosis\Models\Central\SocialAccount;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Models\Role;
use Nvade\Numerosis\Models\Tenant\ApiToken;
use Nvade\Numerosis\Models\Tenant as TenantModels;
use Nvade\Numerosis\Policies\Auth\PermissionPolicy;
use Nvade\Numerosis\Policies\Auth\RolePolicy;
use Nvade\Numerosis\Policies\Auth\SocialAccountPolicy;
use Nvade\Numerosis\Policies\Auth\UserPolicy;
use Nvade\Numerosis\Policies\Billing\PaymentPlanPolicy;
use Nvade\Numerosis\Policies\Billing\PlanFeaturePolicy;
use Nvade\Numerosis\Policies\Billing\SubscriptionPolicy;
use Nvade\Numerosis\Policies\Invitations\InvitationPolicy;
use Nvade\Numerosis\Policies\Tenancy\MembershipPolicy;
use Nvade\Numerosis\Policies\Tenancy\OwnershipNominationPolicy;
use Nvade\Numerosis\Policies\Tenancy\TenantPolicy;
use Nvade\Numerosis\Providers\BillingServiceProvider;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Nvade\Numerosis\Routing\RouteNames;
use Nvade\Numerosis\Services\Auth\GlobalIdSessionHandler;
use Nvade\Numerosis\Services\Exceptions\TenantAwareExceptionContext;
use Nvade\Numerosis\Services\Tenancy\AuthGuardBootstrapper;
use Nvade\Numerosis\Services\Tenancy\PasswordBrokerBootstrapper;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Stancl\Tenancy\Contracts\Tenant;

class NumerosisServiceProvider extends PackageServiceProvider
{
    use PublishesPackageAssets;

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
            ->hasCommand(EndStaleImpersonations::class)
            ->hasCommand(ListClosedTenants::class)
            ->hasCommand(MigrateTenants::class)
            ->hasCommand(ReopenTenantCommand::class)
            ->hasCommand(PruneOrphanedStripeCustomers::class)
            ->hasCommand(PruneOrphanedTenantDatabases::class)
            ->hasCommand(PruneStalledTenantProvisions::class)
            ->hasCommand(PruneActivityLog::class)
            ->hasCommand(BackupTenantCommand::class)
            ->hasCommand(PruneTenantBackups::class)
            ->hasCommand(PruneDataExports::class)
            ->hasCommand(RestoreTenantCommand::class)
            ->hasCommand(ProvisionTenantCommand::class)
            ->hasCommand(ReportUsage::class)
            ->hasCommand(VerifyDomains::class)
            ->hasCommand(PruneNotifications::class)
            ->hasCommand(PruneSessions::class)
            ->hasCommand(ReconcileUsage::class)
            ->hasCommand(TransferTenantOwnershipCommand::class);
    }

    public function packageRegistered(): void
    {
        // Skipped under `config:cache`: the cached `numerosis` key is already
        // complete, and the require below executes a 373-line array of env()
        // calls that read an unloaded $_ENV in that state.
        if (! $this->app->configurationIsCached()) {
            // Not `mergeConfigFrom()`: Laravel merges published config only one
            // level deep, so a host file naming `billing` at all would shadow
            // every sibling key the package later adds under it.
            /** @var array<string, mixed> $current */
            $current = Config::get('numerosis', []);
            /** @var array<string, mixed> $defaults */
            $defaults = require __DIR__.'/../config/numerosis.php';
            Config::set('numerosis', $this->fillMissingKeys($defaults, $current));
        }

        Numerosis::resetModelCache();
        GlobalCache::flush();

        // Deferred until every provider has registered, so config shipped by
        // stancl/tenancy and Laravel itself is normalized once it is merged.
        $this->app->booting(function (): void {
            HostConfig::apply();
        });

        $this->app->register(TenancyServiceProvider::class);
        $this->app->register(BillingServiceProvider::class);

        // Fortify registers its routes on one domain/prefix group. This
        // package needs them per central domain and in the tenant group, so
        // `Numerosis::routes()` loads `routes/routes.php` itself.
        Fortify::ignoreRoutes();

        // `Tenancy::getBootstrappers()` resolves through `app()` on both
        // initialize and end, so a bootstrapper that remembers anything
        // between `bootstrap()` and `revert()` needs one shared instance.
        $this->app->singleton(PasswordBrokerBootstrapper::class);
        $this->app->singleton(AuthGuardBootstrapper::class);

        // Interface-to-concrete mappings, exactly like Fortify's own. A host's
        // `AppServiceProvider` registers after package providers, so
        // overriding either is free, with no opt-in seam to build.
        $this->app->singleton(FortifyLoginResponse::class, NumerosisLoginResponse::class);
        $this->app->singleton(FortifyLogoutResponse::class, NumerosisLogoutResponse::class);

        $this->app->singleton(ProvidesExceptionContext::class, TenantAwareExceptionContext::class);

        // The `Numerosis` facade's accessor. Parameterless, so `swap()` and
        // `spy()` work without the static class being instantiable elsewhere.
        $this->app->singleton(Numerosis::class);

        // `db:seed` resolves this class by name, so an app without one of its
        // own would never run the package's seeders. Define the class and
        // this binding steps aside.
        if (! class_exists('Database\Seeders\DatabaseSeeder')) {
            $this->app->bind('Database\Seeders\DatabaseSeeder', PackageDatabaseSeeder::class);
        }

        $this->registerLivewireComponentNamespaces();

        $this->registerLivewireUploadDisk();
    }

    /**
     * A Livewire namespace maps one prefix to exactly one directory, so these
     * carry the package's own name: claiming `layouts`/`pages` would make a
     * host's views of that name unreachable.
     */
    protected function registerLivewireComponentNamespaces(): void
    {
        foreach (['layouts', 'pages'] as $namespace) {
            Config::set(
                "livewire.component_namespaces.numerosis-{$namespace}",
                __DIR__."/../resources/views/{$namespace}"
            );
        }
    }

    /**
     * Gives Livewire's temporary uploads a disk whose root never moves. Its
     * upload route runs outside tenancy, so a tenant-suffixed disk would write
     * and validate the same file in different directories.
     */
    protected function registerLivewireUploadDisk(): void
    {
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
    }

    public function packageBooted(): void
    {
        Config::set('numerosis.views.path', __DIR__.'/../resources/views');

        $this->registerFactoryResolvers();

        // Sanctum's own model is central-connection-agnostic but carries no
        // `ip_allowlist`; this package's lives in the tenant database.
        Sanctum::usePersonalAccessTokenModel(Numerosis::model(ApiToken::class));

        $this->assertConfiguredStepsAreWellShaped();

        $this->assertPlanMetadataIsWellShaped();

        $this->bootstrapFeatures();

        $this->registerPolicies();

        $this->registerRoutesFallback();

        $this->registerRequestMacros();

        $this->registerEventListeners();

        $this->registerSchedule();

        $this->registerSessionHandler();

        $this->registerMiddleware();

        $this->registerFortify();

        $this->registerGuestRedirect();

        $this->registerExceptionHandling();

        $this->registerLivewireComponents();

        $this->registerBladeDirectives();

        $this->registerPublishing();
    }

    /**
     * Factories ship with this package even when the model is a subclass in a
     * host's own namespace, so both directions of Laravel's default
     * model/factory guessing need replacing.
     */
    protected function registerFactoryResolvers(): void
    {
        Factory::guessFactoryNamesUsing(Numerosis::factoryNameFor(...));
        Factory::guessModelNamesUsing(fn (Factory $factory): string => Numerosis::modelNameFor($factory::class));
    }

    /**
     * The provisioning list's shape, on console boots only.
     *
     * The registration list is checked the same way from
     * {@see Features\Tenancy\RegistrationWizardFeature::bootstrap()},
     * which is where its default is filled in.
     */
    protected function assertConfiguredStepsAreWellShaped(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        ConfiguredSteps::assertEveryProvisioningStepIsOne(ConfiguredSteps::provisioningSteps());
    }

    /**
     * Plan metadata's shape, on console boots only, same terms as
     * {@see self::assertConfiguredStepsAreWellShaped()}. `numerosis:install`
     * calls {@see PlanMetadata::check()} directly for the second, warnings-
     * carrying report.
     */
    protected function assertPlanMetadataIsWellShaped(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        PlanMetadata::assertWellShaped();
    }

    /**
     * A configured feature whose class is not installed logs one warning and
     * is skipped. It also reads as disabled through `FeatureRegistry::enabled()`, so
     * the warning is the only symptom.
     */
    protected function bootstrapFeatures(): void
    {
        foreach (FeatureRegistry::all() as $feature) {
            if (! class_exists($feature)) {
                Log::warning("Numerosis: configured feature [{$feature}] does not exist; skipping.");

                continue;
            }

            $this->app->make($feature)->bootstrap();
        }
    }

    /**
     * `@entitled('custom-branding')` hides what the plan does not sell. Like
     * the route middleware, it is presentation: the action behind the button
     * is what actually refuses.
     */
    protected function registerBladeDirectives(): void
    {
        Blade::if('entitled', fn (string $capability): bool => resolve(Entitlements::class)->allows($capability));
    }

    /**
     * Components addressed by dotted name from this package's own Blade views.
     * Livewire cannot discover package classes on its own.
     */
    protected function registerLivewireComponents(): void
    {
        Livewire::addComponent(name: 'billing.checkout', class: Checkout::class);
        Livewire::addComponent(name: 'settings.delete-user-form', class: DeleteUserForm::class);
        Livewire::addComponent(name: 'settings.connected-accounts', class: ConnectedAccounts::class);
        Livewire::addComponent(name: 'notifications.center', class: NotificationCenter::class);

        // Route middleware does not cover `/livewire/update`, so without this
        // the two-factor screen's password confirmation would hold for the
        // page load and for nothing the user then clicks.
        Livewire::addPersistentMiddleware(RequirePasswordIfSet::class);
    }

    protected function registerPublishing(): void
    {
        // Every `publishGroup()` below is a console-only no-op, so the path
        // and stub arrays it builds are pure waste on an HTTP request.
        if (! $this->app->runningInConsole()) {
            return;
        }

        // The deep-fill backfills every key an override omits, at any depth,
        // so publishing the full file is safe: a host only has to edit what
        // it actually changes.
        $this->publishGroup([
            __DIR__.'/../config/numerosis.php' => config_path('numerosis.php'),
        ], 'numerosis-config');

        // Tenant migrations run per-tenant, never centrally. Publish them
        // only to customize one; tenancy config points at the package copy.
        $this->publishGroup([
            __DIR__.'/../database/migrations/tenant' => database_path('migrations/tenant'),
        ], 'numerosis-tenant-migrations');

        // An empty `routes/tenant.php`, the file `Numerosis::routes()` loads
        // into the tenant group. `routes/web.php` ships with every skeleton.
        $this->publishGroup([
            __DIR__.'/../stubs/routes/tenant.php' => base_path('routes/tenant.php'),
        ], 'numerosis-routes');

        // The tenant entry point `db:seed --class` reaches, published so a
        // host can add its own calls to it.
        $this->publishGroup([
            __DIR__.'/../database/seeders/TenantDatabaseSeeder.php' => database_path('seeders/TenantDatabaseSeeder.php'),
        ], 'numerosis-seeders');

        // Publishes resources/js/numerosis.js for editing. Add it to your
        // Vite config and your build replaces the prebuilt bundle.
        $this->publishGroup(Numerosis::assetSourcePaths(), 'numerosis-assets');

        // The prebuilt bundles themselves, which `Assets::tags()` links to
        // from public/vendor/numerosis. `numerosis:install` checks that same
        // public path to report whether they have been published.
        $publishedAssets = Assets::publishedPaths();

        $this->publishGroup([
            __DIR__.'/../dist/numerosis.css' => $publishedAssets['css'],
            __DIR__.'/../dist/numerosis.js' => $publishedAssets['js'],
        ], 'numerosis-public-assets');

        // Optional subclasses of the package's models, for apps that want to
        // extend them. Every package model is concrete and usable as-is.
        $modelStubs = [];

        foreach (Numerosis::modelStubs() as $relative) {
            $modelStubs[__DIR__."/../stubs/Models/{$relative}.stub"] = app_path("Models/{$relative}.php");
        }

        $this->publishGroup($modelStubs, 'numerosis-models');
    }

    /**
     * Binds each model to its policy explicitly, because the `#[UsePolicy]`
     * each one carries does not reach a host's subclass. Registers the package
     * class, never `Numerosis::model()`'s resolved one, so a host's own
     * `App\Policies\*` convention still wins.
     */
    protected function registerPolicies(): void
    {
        $policies = [
            Invitation::class => InvitationPolicy::class,
            Membership::class => MembershipPolicy::class,
            OwnershipNomination::class => OwnershipNominationPolicy::class,
            PaymentPlan::class => PaymentPlanPolicy::class,
            PlanFeature::class => PlanFeaturePolicy::class,
            SocialAccount::class => SocialAccountPolicy::class,
            Subscription::class => SubscriptionPolicy::class,
            Central\Tenant::class => TenantPolicy::class,
            Permission::class => PermissionPolicy::class,
            Role::class => RolePolicy::class,
            TenantModels\User::class => UserPolicy::class,
        ];

        foreach ($policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
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
     * Swaps the handler on the built store instead of registering a custom
     * driver, so the cookie, encryption and serialization settings stay
     * whatever the host configured.
     */
    protected function registerSessionHandler(): void
    {
        if (Config::get('session.driver') !== 'database') {
            return;
        }

        $store = $this->app->make(SessionManager::class)->driver();

        if (! $store instanceof Store || ! $store->getHandler() instanceof DatabaseSessionHandler) {
            return;
        }

        $connection = Config::get('session.connection');

        $store->setHandler(new GlobalIdSessionHandler(
            $this->app->make(ConnectionResolverInterface::class)->connection(is_string($connection) ? $connection : null),
            Config::string('session.table', 'sessions'),
            Config::integer('session.lifetime'),
            $this->app,
        ));
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

            // Hourly instead of daily: an hour of unreported usage is an hour
            // of a period boundary Stripe may already have closed.
            if (Config::boolean('numerosis.schedule.report_usage')) {
                $schedule->command('billing:report-usage')->hourly()->withoutOverlapping();
            }

            // Claimed domains and serving ones both: DNS pulled from under a
            // live domain has to stop being served.
            if (Config::boolean('numerosis.schedule.verify_domains')) {
                $schedule->command('numerosis:verify-domains')->everyFifteenMinutes()->withoutOverlapping();
            }

            if (Config::boolean('numerosis.schedule.reconcile_usage')) {
                $schedule->command('billing:reconcile-usage')->daily();
            }

            if (Config::boolean('numerosis.schedule.end_stale_impersonations')) {
                $schedule->command('impersonation:end-stale')->everyFifteenMinutes();
            }

            if (Config::boolean('numerosis.schedule.heartbeat')) {
                $schedule->call(static fn (): bool => GlobalCache::store()
                    ->forever(CacheKeys::schedulerHeartbeat(), now()->getTimestamp()))
                    ->everyMinute()
                    ->name('numerosis-scheduler-heartbeat')
                    ->withoutOverlapping();
            }

            if (Config::boolean('numerosis.schedule.prune_stalled_provisions')) {
                $schedule->command('tenancy:prune-stalled-provisions')->hourly();
            }

            // `--force` because a scheduled run has nobody to confirm to, and
            // off by default because this one drops databases.
            if (Config::boolean('numerosis.schedule.prune_orphaned_databases')) {
                $schedule->command('tenancy:prune-orphaned-databases', ['--force' => true])->daily();
            }

            if (Config::boolean('numerosis.schedule.prune_data_exports')) {
                $schedule->command('numerosis:prune-data-exports')->daily();
            }

            if (Config::boolean('numerosis.schedule.prune_tenant_backups')) {
                $schedule->command('numerosis:prune-tenant-backups')->daily();
            }

            if (Config::boolean('numerosis.schedule.prune_notifications')) {
                $schedule->command('numerosis:prune-notifications')->daily();
            }

            // `activitylog.clean_after_days` decides the window; the tenant
            // databases' own logs are cleaned by the same command run inside
            // tenancy, which is the host's own scheduling decision.
            if (Config::boolean('numerosis.schedule.prune_activity_log')) {
                $schedule->command('numerosis:prune-activity-log')->daily();
            }

            if (Config::boolean('numerosis.schedule.prune_sessions')) {
                $schedule->command('numerosis:prune-sessions')->daily();
            }

            // Resolved through Numerosis::model() so a host that subclassed
            // Invitation prunes its own class. Invitation::prunable() keeps
            // accepted and expired rows for 30 days.
            if (Config::boolean('numerosis.schedule.prune_invitations')) {
                $schedule->command('model:prune', [
                    '--model' => [
                        Numerosis::model(Invitation::class),
                        Numerosis::model(OwnershipNomination::class),
                    ],
                ])->daily();
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
        /** @var array<class-string, list<class-string>> $listeners */
        $listeners = [
            SocialAccountLinked::class => [LogSocialAccountLinked::class],
            SocialAccountUnlinked::class => [LogSocialAccountUnlinked::class],
            InvitationCreated::class => [SendInvitationNotification::class],
            PaymentSettled::class => [SendPaymentConfirmedNotification::class],
            PaymentFailed::class => [SendPaymentFailedNotification::class],
            TenantSuspended::class => [SendTenantSuspendedNotification::class],
            TenantRestored::class => [SendTenantRestoredNotification::class],
            TenantProvisioned::class => [BackfillTenantUsers::class],

            // A revoked member's API token is the same breach as their
            // session, and both have to go.
            MemberRemoved::class => [EndSessionsForRemovedMember::class, RevokeApiTokensForRemovedMember::class],

            TenantProvisioningFailed::class => [SendProvisioningFailedAlert::class],
            MigrationsEnded::class => [ForgetTenantColumnListing::class],

            // Both events cancel the send when their listener returns false,
            // which is how support avoids mailing a customer from inside
            // their own account.
            MessageSending::class => [SuppressMailWhileImpersonating::class],
            NotificationSending::class => [SuppressMailWhileImpersonating::class],
            // Auto-discovery only scans a host's `app/Listeners`, never a
            // package's `src/`, so this explicit registration is the only
            // thing that makes {@see EndOtherGuardSession} fire.
            Logout::class => [EndOtherGuardSession::class],

            PasswordChanged::class => [RevokeSessionsAfterPasswordChange::class],
            TwoFactorAuthenticationDisabled::class => [RevokeSessionsAfterTwoFactorDisabled::class],
        ];

        foreach (RecordDomainEventActivity::AUDITED_EVENTS as $event) {
            $listeners[$event][] = RecordDomainEventActivity::class;
        }

        foreach ($listeners as $event => $eventListeners) {
            foreach ($eventListeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }

    /**
     * Registers the same aliases and groups as {@see Numerosis::middleware()},
     * for an app that never calls it from `bootstrap/app.php`. A host that did
     * call it keeps whatever it configured afterwards, since running again
     * here would revert its trust settings and alias swaps.
     */
    protected function registerMiddleware(): void
    {
        if (MiddlewareRegistrar::$registerCallback instanceof Closure) {
            (MiddlewareRegistrar::$registerCallback)($this->app);

            return;
        }

        if (Numerosis::middlewareRegistered()) {
            return;
        }

        $this->seedMiddlewareBaselineIfMissing();

        foreach (Numerosis::middlewareAliases() as $alias => $middleware) {
            Route::aliasMiddleware($alias, $middleware);
        }

        foreach (Numerosis::middlewareGroups() as $name => $stack) {
            Route::middlewareGroup($name, $stack);
        }

        foreach (Numerosis::middlewareGroupAppends() as $name => $stack) {
            foreach ($stack as $middleware) {
                Route::pushMiddlewareToGroup($name, $middleware);
            }
        }

        // Defaults to trusting nobody. See `numerosis.trusted_proxies`. A
        // host behind a real proxy that relies on this fallback instead of
        // wiring `Numerosis::middleware()` itself must set that config key.
        TrustProxies::at(MiddlewareRegistrar::trustedProxies());

        $this->app->make(Kernel::class)->prependMiddleware(TrustHosts::class);
    }

    /**
     * Replaces the `redirectUsing(fn () => route('login'))` that
     * `ApplicationBuilder::withMiddleware()` always registers, which throws
     * `RouteNotFoundException` until Fortify registers `login`. Falls back to
     * `home` until then, and is inert once that route exists.
     */
    protected function registerGuestRedirect(): void
    {
        Authenticate::redirectUsing(
            fn () => Route::has('login') ? route('login') : route(RouteNames::home())
        );
    }

    /**
     * Wires this package's own actions into Fortify's published seams,
     * customized the way Fortify's own docs describe. `numerosis.features`
     * and `fortify.features` stay separate: numerosis's gates
     * tenancy/billing surfaces, Fortify's gates auth screens.
     */
    protected function registerFortify(): void
    {
        Fortify::viewPrefix('numerosis::auth.');

        // `fortify.features` is defaulted by `HostConfig::fortifyFeatures()`,
        // under the stock-value rule every backfill follows. Setting it here
        // would discard a host's published `config/fortify.php` on every boot.
        $this->app->bind(FortifyLoginRequest::class, NumerosisLoginRequest::class);

        Fortify::createUsersUsing(CreateRegisteredUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfile::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Order is load-bearing: `CanonicalizeUsername` has to precede the
        // OTP step, which looks its candidate up by exact email match, and
        // `LogInToCentralGuard` has to run last.
        Fortify::authenticateThrough(fn (Request $request): array => array_filter([
            Config::get('fortify.limiters.login') !== null ? null : EnsureLoginIsNotThrottled::class,
            Config::boolean('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
            OneTimePasswordFeature::available() ? RedirectIfOneTimePasswordAuthenticatable::class : null,

            // After the OTP step, which never calls `$next()`: an emailed code
            // already proves possession, so that login is not challenged for a
            // second one.
            FortifyFeatures::canManageTwoFactorAuthentication() ? RedirectIfTwoFactorAuthenticatable::class : null,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
            LogInToCentralGuard::class,
        ]));

        $this->registerAuthRateLimiters();

        // `VerifyEmailController` type-hints Fortify's concrete
        // `VerifyEmailRequest`, and binding a subclass to it is the only way
        // to compare against `getGlobalIdentifierKey()`.
        $this->app->bind(FortifyVerifyEmailRequest::class, NumerosisVerifyEmailRequest::class);
        $this->app->singleton(FortifyVerifyEmailResponse::class, NumerosisVerifyEmailResponse::class);
    }

    /**
     * The login limiter replaces Fortify's default, which keys on
     * `lower(username).'|'.$request->ip()` and so shares one lockout counter
     * between two tenants holding a user at the same address. Regression-test
     * either one with two tenants; a single-tenant test passes whether or not
     * the tenant key is in the bucket.
     */
    protected function registerAuthRateLimiters(): void
    {
        Config::set('fortify.limiters.login', 'login');

        RateLimiter::for('login', function (Request $request): Limit {
            $email = (string) $request->string(Fortify::username());
            $key = $this->authThrottleKey($request, $email);

            // Throws instead of returning, which is what the limiter does
            // without a response callback; the callback exists only to reach
            // the exhaustion moment.
            return Limit::perMinute(5)->by($key)->response(
                function (Request $request, array $headers) use ($email, $key): never {
                    $this->reportExhaustedLoginLimiter($request, $email, $key);

                    throw new ThrottleRequestsException('Too Many Attempts.', null, $headers);
                }
            );
        });

        RateLimiter::for(OneTimePasswordFeature::LIMITER, fn (Request $request): Limit => Limit::perMinute(5)->by(
            $this->authThrottleKey($request, $this->pendingLoginAddress($request))
        ));

        Config::set('fortify.limiters.two-factor', 'two-factor');

        // Six digits stays guessable at five tries a minute over an evening,
        // so the challenge gets a tighter window than login.
        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinutes(5, 5)->by(
            $this->twoFactorThrottleKey($request)
        ));

        RateLimiter::for('social', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));

        // Keyed per token instead of per IP, so two tenants behind one NAT
        // cannot spend each other's budget. Falls back to the address for
        // an unauthenticated call.
        RateLimiter::for('numerosis-api', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $key = $token instanceof ApiToken ? $token->getKey() : null;
            $tokenId = is_int($key) || is_string($key) ? (string) $key : null;

            return Limit::perMinute(Config::integer('numerosis.api.rate_limit', 60))
                ->by($tokenId === null ? 'ip:'.$request->ip() : 'token:'.$tokenId);
        });
    }

    /**
     * Tenant + the account the password step challenged + IP. Keyed on
     * `login.id` instead of the session id, so cycling the session cookie
     * does not hand the same pending login a fresh set of guesses; the request
     * never carries that id, so a caller cannot choose whose bucket to spend.
     */
    protected function twoFactorThrottleKey(Request $request): string
    {
        $tenant = tenancy()->tenant;
        $tenantKey = $tenant instanceof Tenant ? (string) $tenant->getTenantKey() : 'central';
        $challenged = $request->hasSession() ? $request->session()->get('login.id') : null;

        return $tenantKey.'|'.(is_scalar($challenged) ? (string) $challenged : '').'|'.$request->ip();
    }

    /**
     * The address the OTP send leg stashed, which is what keys the challenge's
     * limiter. The challenge form deliberately does not carry it, so a caller
     * cannot choose whose bucket to spend.
     */
    protected function pendingLoginAddress(Request $request): string
    {
        $email = $request->hasSession() ? $request->session()->get(SessionKey::LoginEmail->value) : null;

        return is_string($email) ? $email : '';
    }

    /**
     * One {@see SuspiciousLoginDetected} per lockout window, never per blocked
     * attempt: the marker is added for the limiter's own decay period and
     * every later attempt in that window finds it already there.
     */
    protected function reportExhaustedLoginLimiter(Request $request, string $email, string $key): void
    {
        if (! GlobalCache::claim(CacheKeys::loginLockout($key), 60)) {
            return;
        }

        $tenant = tenancy()->tenant;

        event(new SuspiciousLoginDetected(Str::lower($email), $tenant instanceof Tenant ? (string) $tenant->getTenantKey() : null, $request->ip()));
    }

    /**
     * Tenant + address + IP. The tenant key is what keeps two tenants holding
     * the same address off one another's lockout counter.
     */
    protected function authThrottleKey(Request $request, string $email): string
    {
        $tenant = tenancy()->tenant;
        $tenantKey = $tenant instanceof Tenant ? (string) $tenant->getTenantKey() : 'central';

        return $tenantKey.'|'.Str::lower($email).'|'.$request->ip();
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
     * Applies {@see Numerosis::exceptions()} for an app that never called it,
     * binding `ExceptionHandler::class` first if `->withExceptions()` never
     * ran either. Skipped for a host that replaced Laravel's handler.
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

    /**
     * Backfills `config/numerosis.php` defaults at every depth. Only keyed
     * arrays are filled, so a list such as `features` is left exactly as the
     * host set it, including empty.
     *
     * @param  array<array-key, mixed>  $default
     * @param  array<array-key, mixed>  $current
     * @return array<array-key, mixed>
     */
    private function fillMissingKeys(array $default, array $current): array
    {
        $merged = $current;

        foreach ($default as $key => $value) {
            if (! array_key_exists($key, $current)) {
                $merged[$key] = $value;

                continue;
            }

            $existing = $current[$key];

            if (is_array($value) && is_array($existing) && ! array_is_list($value) && ! array_is_list($existing)) {
                $merged[$key] = $this->fillMissingKeys($value, $existing);
            }
        }

        return $merged;
    }
}

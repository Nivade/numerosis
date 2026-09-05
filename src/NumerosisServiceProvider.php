<?php

declare(strict_types=1);

namespace Nvade\Numerosis;

use Closure;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\LoginResponse as FortifyLoginResponse;
use Laravel\Fortify\Contracts\LogoutResponse as FortifyLogoutResponse;
use Laravel\Fortify\Contracts\VerifyEmailResponse as FortifyVerifyEmailResponse;
use Laravel\Fortify\Features as FortifyFeatures;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\LoginRequest as FortifyLoginRequest;
use Laravel\Fortify\Http\Requests\VerifyEmailRequest as FortifyVerifyEmailRequest;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Auth\AuthenticateLoginCandidate;
use Nvade\Numerosis\Actions\Auth\CreateRegisteredUser;
use Nvade\Numerosis\Actions\Auth\LogInToCentralGuard;
use Nvade\Numerosis\Actions\Auth\RedirectIfOneTimePasswordAuthenticatable;
use Nvade\Numerosis\Actions\Auth\ResetUserPassword;
use Nvade\Numerosis\Actions\Auth\ResolveLoginCandidate;
use Nvade\Numerosis\Actions\Auth\SendEmailVerificationNotification;
use Nvade\Numerosis\Actions\Auth\UpdateUserPassword;
use Nvade\Numerosis\Actions\Auth\UpdateUserProfile;
use Nvade\Numerosis\Commands\InstallNumerosisCommand;
use Nvade\Numerosis\Concerns\PublishesPackageAssets;
use Nvade\Numerosis\Console\Commands\DeleteTenants;
use Nvade\Numerosis\Console\Commands\PruneOrphanedStripeCustomers;
use Nvade\Numerosis\Console\Commands\PruneOrphanedTenantDatabases;
use Nvade\Numerosis\Console\Commands\PruneStalledTenantProvisions;
use Nvade\Numerosis\Contracts\Auth\AuthenticatesLoginCandidate;
use Nvade\Numerosis\Contracts\Auth\ResolvesLoginCandidate;
use Nvade\Numerosis\Contracts\Auth\SendsEmailVerificationNotification;
use Nvade\Numerosis\Contracts\Notifications\NotifiesTenantOwner;
use Nvade\Numerosis\Database\Seeders\DatabaseSeeder as PackageDatabaseSeeder;
use Nvade\Numerosis\Events\Auth\SocialAccountLinked;
use Nvade\Numerosis\Events\Auth\SocialAccountUnlinked;
use Nvade\Numerosis\Events\Billing\PaymentFailed;
use Nvade\Numerosis\Events\Billing\PaymentSettled;
use Nvade\Numerosis\Events\Billing\TenantSuspended;
use Nvade\Numerosis\Events\Invitations\InvitationCreated;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioned;
use Nvade\Numerosis\Events\Tenancy\TenantRestored;
use Nvade\Numerosis\Features\Auth\OneTimePasswordFeature;
use Nvade\Numerosis\Http\Middleware\Authenticate;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Http\Middleware\EnsureTenantSubscriptionActive;
use Nvade\Numerosis\Http\Middleware\RequirePasswordIfSet;
use Nvade\Numerosis\Http\Requests\Auth\NumerosisLoginRequest;
use Nvade\Numerosis\Http\Requests\Auth\NumerosisVerifyEmailRequest;
use Nvade\Numerosis\Http\Responses\Auth\NumerosisLoginResponse;
use Nvade\Numerosis\Http\Responses\Auth\NumerosisLogoutResponse;
use Nvade\Numerosis\Http\Responses\Auth\NumerosisVerifyEmailResponse;
use Nvade\Numerosis\Listeners\Auth\EndOtherGuardSession;
use Nvade\Numerosis\Listeners\Auth\LogSocialAccountLinked;
use Nvade\Numerosis\Listeners\Auth\LogSocialAccountUnlinked;
use Nvade\Numerosis\Listeners\Billing\SendPaymentConfirmedNotification;
use Nvade\Numerosis\Listeners\Billing\SendPaymentFailedNotification;
use Nvade\Numerosis\Listeners\Billing\SendTenantSuspendedNotification;
use Nvade\Numerosis\Listeners\Invitations\SendInvitationNotification;
use Nvade\Numerosis\Listeners\Tenancy\BackfillTenantUsers;
use Nvade\Numerosis\Listeners\Tenancy\SendTenantRestoredNotification;
use Nvade\Numerosis\Livewire\Billing\Checkout;
use Nvade\Numerosis\Livewire\Settings\ConnectedAccounts;
use Nvade\Numerosis\Livewire\Settings\DeleteUserForm;
use Nvade\Numerosis\Models\Central;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Models\Role;
use Nvade\Numerosis\Models\Tenant as TenantModels;
use Nvade\Numerosis\Policies\InvitationPolicy;
use Nvade\Numerosis\Policies\PaymentPlanPolicy;
use Nvade\Numerosis\Policies\PermissionPolicy;
use Nvade\Numerosis\Policies\PlanFeaturePolicy;
use Nvade\Numerosis\Policies\RolePolicy;
use Nvade\Numerosis\Policies\SocialAccountPolicy;
use Nvade\Numerosis\Policies\SubscriptionPolicy;
use Nvade\Numerosis\Policies\TenantPolicy;
use Nvade\Numerosis\Policies\UserPolicy;
use Nvade\Numerosis\Providers\BillingServiceProvider;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Nvade\Numerosis\Services\Notifications\NotifiesTenantOwnerDirectly;
use Nvade\Numerosis\Services\Tenancy\Bootstrappers\PasswordBrokerBootstrapper;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\HostConfig;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\Numerosis\Support\Routes\RouteNames;
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
            ->hasCommand(PruneOrphanedStripeCustomers::class)
            ->hasCommand(PruneOrphanedTenantDatabases::class)
            ->hasCommand(PruneStalledTenantProvisions::class);
    }

    public function packageRegistered(): void
    {
        // Not `mergeConfigFrom()`: Laravel merges published config only one
        // level deep, so a host file naming `billing` at all would shadow
        // every sibling key the package later adds under it.
        /** @var array<string, mixed> $current */
        $current = Config::get('numerosis', []);
        /** @var array<string, mixed> $defaults */
        $defaults = require __DIR__.'/../config/numerosis.php';
        Config::set('numerosis', $this->fillMissingKeys($defaults, $current));

        Numerosis::resetModelCache();

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

        $this->app->bind(ResolvesLoginCandidate::class, ResolveLoginCandidate::class);
        $this->app->bind(AuthenticatesLoginCandidate::class, AuthenticateLoginCandidate::class);
        $this->app->bind(SendsEmailVerificationNotification::class, SendEmailVerificationNotification::class);

        // Interface-to-concrete mappings, exactly like Fortify's own. A host's
        // `AppServiceProvider` registers after package providers, so
        // overriding either is free, with no opt-in seam to build.
        $this->app->singleton(FortifyLoginResponse::class, NumerosisLoginResponse::class);
        $this->app->singleton(FortifyLogoutResponse::class, NumerosisLogoutResponse::class);
        $this->app->bind(NotifiesTenantOwner::class, NotifiesTenantOwnerDirectly::class);

        // `db:seed` resolves this class by name, so an app without one of its
        // own would never run the package's seeders. Define the class and
        // this binding steps aside.
        if (! class_exists('Database\Seeders\DatabaseSeeder')) {
            $this->app->bind('Database\Seeders\DatabaseSeeder', PackageDatabaseSeeder::class);
        }

        // Points `layouts::` and `pages::` at this package's views, leaving
        // anything a host already set. Livewire bakes these into the view
        // finder during its boot(), so a host has to set them in register().
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

        $this->registerPolicies();

        $this->registerRoutesFallback();

        $this->registerRequestMacros();

        $this->registerEventListeners();

        $this->registerSchedule();

        $this->registerMiddleware();

        $this->registerFortify();

        $this->registerGuestRedirect();

        $this->registerBroadcasting();

        $this->registerExceptionHandling();

        // Components addressed by dotted name from this package's own Blade
        // views. Livewire cannot discover package classes on its own.
        Livewire::addComponent(name: 'billing.checkout', class: Checkout::class);
        Livewire::addComponent(name: 'settings.delete-user-form', class: DeleteUserForm::class);
        Livewire::addComponent(name: 'settings.connected-accounts', class: ConnectedAccounts::class);

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

        // Publishes resources/js/numerosis.js for editing. Add it to your
        // Vite config and your build replaces the prebuilt bundle.
        $this->publishGroup(Numerosis::assetSourcePaths(), 'numerosis-assets');

        // The prebuilt bundles themselves, which `Assets::tags()` links to
        // from public/vendor/numerosis. `numerosis:install` checks that same
        // public path to report whether they have been published.
        $this->publishGroup([
            __DIR__.'/../dist/numerosis.css' => public_path('vendor/numerosis/'.self::ASSET_ID.'.css'),
            __DIR__.'/../dist/numerosis.js' => public_path('vendor/numerosis/'.self::ASSET_ID.'.js'),
        ], 'numerosis-public-assets');

        // Optional subclasses of the package's models, for apps that want to
        // extend them. Every package model is concrete and usable as-is.
        $modelStubs = [
            __DIR__.'/../stubs/Models/Central/Tenant.stub' => app_path('Models/Central/Tenant.php'),
            __DIR__.'/../stubs/Models/Central/Domain.stub' => app_path('Models/Central/Domain.php'),
            __DIR__.'/../stubs/Models/Central/CentralUser.stub' => app_path('Models/Central/CentralUser.php'),
            __DIR__.'/../stubs/Models/Central/Subscription.stub' => app_path('Models/Central/Subscription.php'),
            __DIR__.'/../stubs/Models/Central/PaymentPlan.stub' => app_path('Models/Central/PaymentPlan.php'),
            __DIR__.'/../stubs/Models/Central/PendingTenantProvision.stub' => app_path('Models/Central/PendingTenantProvision.php'),
            __DIR__.'/../stubs/Models/Central/Invitation.stub' => app_path('Models/Central/Invitation.php'),
            __DIR__.'/../stubs/Models/Central/SocialAccount.stub' => app_path('Models/Central/SocialAccount.php'),
            __DIR__.'/../stubs/Models/Tenant/User.stub' => app_path('Models/Tenant/User.php'),
        ];

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
            Central\Invitation::class => InvitationPolicy::class,
            Central\PaymentPlan::class => PaymentPlanPolicy::class,
            Central\PlanFeature::class => PlanFeaturePolicy::class,
            Central\SocialAccount::class => SocialAccountPolicy::class,
            Central\Subscription::class => SubscriptionPolicy::class,
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

            // Resolved through Numerosis::model() so a host that subclassed
            // Invitation prunes its own class. Invitation::prunable() keeps
            // accepted and expired rows for 30 days.
            if (Config::boolean('numerosis.schedule.prune_invitations')) {
                $schedule->command('model:prune', [
                    '--model' => [Numerosis::model(Central\Invitation::class)],
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
        $listeners = [
            SocialAccountLinked::class => LogSocialAccountLinked::class,
            SocialAccountUnlinked::class => LogSocialAccountUnlinked::class,
            InvitationCreated::class => SendInvitationNotification::class,
            PaymentSettled::class => SendPaymentConfirmedNotification::class,
            PaymentFailed::class => SendPaymentFailedNotification::class,
            TenantSuspended::class => SendTenantSuspendedNotification::class,
            TenantRestored::class => SendTenantRestoredNotification::class,
            TenantProvisioned::class => BackfillTenantUsers::class,
            // Auto-discovery only scans a host's `app/Listeners`, never a
            // package's `src/`, so this explicit registration is the only
            // thing that makes {@see EndOtherGuardSession} fire.
            Logout::class => EndOtherGuardSession::class,
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

        // Laravel's `auth`, plus the central-to-tenant session promotion. It
        // takes its own alias because `auth` is the host's, and every central
        // route using that one must keep Laravel's own behaviour.
        Route::aliasMiddleware('tenancy.auth', Authenticate::class);

        // Kept in step by hand with the same alias in
        // `Support\Numerosis::middleware()`; no test enforces it.
        Route::aliasMiddleware('password.confirm.if-set', RequirePasswordIfSet::class);

        // The suspension gate. Apply it per route group; it redirects to
        // `tenant.suspended`, which is itself a tenant route, so adding it to
        // the `tenant` group as a whole loops.
        Route::aliasMiddleware('tenancy.subscription', EnsureTenantSubscriptionActive::class);
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
     * Replaces the `redirectUsing(fn () => route('login'))` that
     * `ApplicationBuilder::withMiddleware()` always registers, which throws
     * `RouteNotFoundException` until Fortify registers `login`. Falls back to
     * `home` until then, and is inert once that route exists.
     */
    protected function registerGuestRedirect(): void
    {
        \Illuminate\Auth\Middleware\Authenticate::redirectUsing(
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
            Config::get('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class,
            Config::get('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
            OneTimePasswordFeature::available() ? RedirectIfOneTimePasswordAuthenticatable::class : null,
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
     * Both limiters replace Fortify's default, which keys on
     * `lower(username).'|'.$request->ip()` and so shares one lockout counter
     * between two tenants holding a user at the same address. Regression-test
     * either one with two tenants; a single-tenant test passes whether or not
     * the tenant key is in the bucket.
     */
    protected function registerAuthRateLimiters(): void
    {
        Config::set('fortify.limiters.login', 'login');

        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)->by(
            $this->authThrottleKey($request, (string) $request->string(Fortify::username()))
        ));

        RateLimiter::for(OneTimePasswordFeature::LIMITER, fn (Request $request): Limit => Limit::perMinute(5)->by(
            $this->authThrottleKey($request, $this->pendingLoginAddress($request))
        ));

        RateLimiter::for('social', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
    }

    /**
     * The address the OTP send leg stashed, which is what keys the challenge's
     * limiter. The challenge form deliberately does not carry it, so a caller
     * cannot choose whose bucket to spend.
     */
    protected function pendingLoginAddress(Request $request): string
    {
        $email = $request->hasSession() ? $request->session()->get('login.email') : null;

        return is_string($email) ? $email : '';
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
     * Registers `/broadcasting/auth` and the package's channels, so calling
     * `withBroadcasting()` yourself is optional; do both and the route is
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

        // `require_once` would leave channels unregistered on the second and
        // later application boots within one process.
        require Numerosis::broadcastChannelsPath();
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

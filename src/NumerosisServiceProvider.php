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
use Nvade\Numerosis\Actions\Invitations\CreateInvitedUser;
use Nvade\Numerosis\Commands\InstallNumerosisCommand;
use Nvade\Numerosis\Concerns\PublishesPackageAssets;
use Nvade\Numerosis\Console\Commands\DeleteTenants;
use Nvade\Numerosis\Console\Commands\PruneOrphanedStripeCustomers;
use Nvade\Numerosis\Console\Commands\PruneOrphanedTenantDatabases;
use Nvade\Numerosis\Console\Commands\PruneStalledTenantProvisions;
use Nvade\Numerosis\Contracts\Auth\AuthenticatesLoginCandidate;
use Nvade\Numerosis\Contracts\Auth\ResolvesLoginCandidate;
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
use Nvade\Numerosis\Events\Tenancy\TenantProvisioned;
use Nvade\Numerosis\Events\Tenancy\TenantRestored;
use Nvade\Numerosis\Features\Auth\OneTimePasswordFeature;
use Nvade\Numerosis\Http\Middleware\Authenticate;
use Nvade\Numerosis\Http\Middleware\CheckInvitationStatus;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Http\Middleware\EnsureTenantSubscriptionActive;
use Nvade\Numerosis\Http\Requests\Auth\NumerosisLoginRequest;
use Nvade\Numerosis\Http\Requests\Auth\NumerosisVerifyEmailRequest;
use Nvade\Numerosis\Http\Responses\Auth\NumerosisLoginResponse;
use Nvade\Numerosis\Http\Responses\Auth\NumerosisLogoutResponse;
use Nvade\Numerosis\Http\Responses\Auth\NumerosisVerifyEmailResponse;
use Nvade\Numerosis\Listeners\Auth\EndOtherGuardSession;
use Nvade\Numerosis\Listeners\Auth\LogSocialAccountConnected;
use Nvade\Numerosis\Listeners\Auth\LogSocialAccountDisconnected;
use Nvade\Numerosis\Listeners\Billing\SendPaymentConfirmedNotification;
use Nvade\Numerosis\Listeners\Billing\SendPaymentFailedNotification;
use Nvade\Numerosis\Listeners\Billing\SendTenantSuspendedNotification;
use Nvade\Numerosis\Listeners\Invitations\SendInvitationNotification;
use Nvade\Numerosis\Listeners\Tenancy\BackfillTenantUsers;
use Nvade\Numerosis\Listeners\Tenancy\SendTenantRestoredNotification;
use Nvade\Numerosis\Livewire\Billing\Checkout;
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
use Nvade\Numerosis\Policies\SubscriptionPolicy;
use Nvade\Numerosis\Policies\TenantPolicy;
use Nvade\Numerosis\Policies\UserPolicy;
use Nvade\Numerosis\Providers\BillingServiceProvider;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Nvade\Numerosis\Services\Auth\EloquentSocialAccountRepository;
use Nvade\Numerosis\Services\Invitations\EloquentInvitationRepository;
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
        // Done here rather than through `hasConfigFile('numerosis')`, which
        // would also register the package's own config file for publishing —
        // and that file must never be published: it assembles the thirteen
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

        // Fortify registers its own routes on one domain/prefix group; this
        // package needs them on every central domain *and* inside the tenant
        // group instead, so `Numerosis::routes()` loads `routes/routes.php`
        // itself, per group. See `.claude/plans/archive/humming-nibbling-flame.md`
        // Phase 4a.
        Fortify::ignoreRoutes();

        // Singleton, not a bind: `Tenancy::getBootstrappers()` resolves the
        // configured bootstrappers through `app()` on *both* initialize and
        // end, so anything remembering state between `bootstrap()` and
        // `revert()` needs one shared instance. See the class docblock.
        $this->app->singleton(PasswordBrokerBootstrapper::class);

        $this->app->bind(ResolvesLoginCandidate::class, ResolveLoginCandidate::class);
        $this->app->bind(AuthenticatesLoginCandidate::class, AuthenticateLoginCandidate::class);
        $this->app->bind(SendsEmailVerificationNotification::class, SendEmailVerificationNotification::class);
        $this->app->bind(CreatesInvitedUser::class, CreateInvitedUser::class);

        // Interface-to-concrete mappings, exactly like Fortify's own — a
        // host's `AppServiceProvider` registers after package providers, so
        // overriding either is free, with no opt-in seam to build.
        $this->app->singleton(FortifyLoginResponse::class, NumerosisLoginResponse::class);
        $this->app->singleton(FortifyLogoutResponse::class, NumerosisLogoutResponse::class);
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

        // The prebuilt bundles themselves, which `Assets::tags()` links to
        // from public/vendor/numerosis. Until Filament was dropped these were
        // registered as Filament assets and copied by `filament:assets`; a
        // plain publish group is the replacement, and is why `numerosis:install`
        // now checks the public path rather than public/css/filament.
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
            __DIR__.'/../stubs/Models/Tenant/User.stub' => app_path('Models/Tenant/User.php'),
            __DIR__.'/../stubs/Models/Tenant/Invitation.stub' => app_path('Models/Tenant/Invitation.php'),
        ];

        $this->publishGroup($modelStubs, 'numerosis-models');
    }

    /**
     * Binds each model to its policy explicitly, so a host's **subclass** of
     * one of these models is covered too.
     *
     * Every model below also carries `#[UsePolicy]`, and that attribute alone
     * is not enough: **PHP attributes are not inherited**, and
     * `Gate::getPolicyFor()` reads them off the exact class it is handed.
     * Any caller resolving a model through `Numerosis::model(...)` gets, on a
     * host using the documented model-override seam, a **subclass** — so
     * before this, `Central\{Tenant,PaymentPlan,Subscription}` resolved **no
     * policy at all**, and a `Gate::allows()` against a model with no policy
     * falls through to whatever the caller does with an unauthorized answer.
     * Measured on the Workbench host, not inferred; the `Tenant\*` models
     * escaped it only because their subclasses re-declare the attribute by
     * hand.
     *
     * **Registered against the package class, not `Numerosis::model()`'s
     * resolved one**, which is what makes a host's own convention still win.
     * `Gate::getPolicyFor()` tries, in order: an exact entry in the policy map,
     * the attribute, the `App\Policies\*` name guess, and only then a
     * `is_subclass_of` sweep of the map. Registering the resolved subclass
     * would take that first branch and silently beat a host's own
     * `App\Policies\Central\TenantPolicy`; registering the base leaves the
     * guesser ahead of us and still catches every subclass.
     *
     * `CentralUser` is deliberately absent — see `.ai/rules/auth-guards.md`
     * for why giving it a policy is a behaviour change that needs deciding
     * rather than a gap to close here.
     */
    protected function registerPolicies(): void
    {
        $policies = [
            Central\PaymentPlan::class => PaymentPlanPolicy::class,
            Central\PlanFeature::class => PlanFeaturePolicy::class,
            Central\Subscription::class => SubscriptionPolicy::class,
            Central\Tenant::class => TenantPolicy::class,
            Permission::class => PermissionPolicy::class,
            Role::class => RolePolicy::class,
            TenantModels\Invitation::class => InvitationPolicy::class,
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
            TenantRestored::class => SendTenantRestoredNotification::class,
            TenantProvisioned::class => BackfillTenantUsers::class,
            InvitationIssued::class => SendInvitationNotification::class,
            // Laravel's listener auto-discovery only scans a host app's
            // `app/Listeners`, never a package's `src/` — an explicit
            // `Event::listen()` is the only way this ever fires. See
            // `EndOtherGuardSession`'s own docblock.
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

        Route::aliasMiddleware('invitation.status', CheckInvitationStatus::class);

        // Laravel's `auth`, plus the central→tenant session promotion. Not
        // registered as `auth` itself: that alias is the host's, and every
        // central route that uses it must keep Laravel's own behaviour.
        Route::aliasMiddleware('tenancy.auth', Authenticate::class);

        // The suspension gate, applied per-group rather than to the `tenant`
        // group as a whole: it redirects to `tenant.suspended`, which is
        // itself a tenant route, so a group-wide registration loops.
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
     * `Illuminate\Foundation\Configuration\ApplicationBuilder::withMiddleware()`
     * always registers `Authenticate::redirectUsing(fn () => route('login'))`
     * before running a host's own callback — plain Laravel skeleton
     * behaviour, unconditional whether or not a host passes one. `login`
     * belonged to nvade/numerosis-auth-ui until that package folded into
     * core in Phase 3 of `.claude/plans/archive/humming-nibbling-flame.md`, and its
     * Livewire screens were deleted rather than moved (Phase 4 rebuilds them
     * on Fortify) — so the stock default now throws RouteNotFoundException
     * on every guest request to a protected route instead of redirecting
     * one. Set directly here (not through {@see Numerosis::middleware()}'s
     * `$middleware->redirectGuestsTo()`) because that object only reaches
     * `Authenticate` when a host's `bootstrap/app.php` passes it to
     * `withMiddleware()`; Testbench, and any host that never calls
     * {@see Numerosis::middleware()}, do not. Falls back to `home` until the
     * route exists again; once Fortify registers it, this override is inert.
     */
    protected function registerGuestRedirect(): void
    {
        \Illuminate\Auth\Middleware\Authenticate::redirectUsing(
            fn () => Route::has('login') ? route('login') : route(RouteNames::home())
        );
    }

    /**
     * Wires this package's own actions into Fortify's published seams,
     * customized the way Fortify's own docs describe — see
     * `.claude/plans/archive/humming-nibbling-flame.md` Phase 4e. `numerosis.features`
     * and `fortify.features` stay separate: numerosis's gates
     * tenancy/billing surfaces, Fortify's gates auth screens.
     */
    protected function registerFortify(): void
    {
        Fortify::viewPrefix('numerosis::auth.');

        // `fortify.features` is a *default*, not an override, and it is set
        // in `HostConfig::fortifyFeatures()` with the same "only while the
        // key still holds the stock value" rule every other backfill in this
        // package follows. Setting it here instead would discard a host's
        // published `config/fortify.php` on every boot, while
        // `docs/extending.md` goes on naming that key as the seam for
        // choosing which auth screens exist.
        $this->app->bind(FortifyLoginRequest::class, NumerosisLoginRequest::class);

        Fortify::createUsersUsing(CreateRegisteredUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfile::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // `LoginUser`'s dual-guard login runs last, once Fortify's own two
        // steps have logged the request's own (central or tenant) guard in.
        //
        // `CanonicalizeUsername` is carried over from Fortify's own default
        // pipeline rather than dropped: replacing the pipeline wholesale is
        // what silently turns `fortify.lowercase_usernames` into a dead
        // config key. It has to stay *ahead* of the OTP step, whose candidate
        // lookup is an exact-match `firstWhere('email', …)`, so that the
        // address the limiter keys on and the address the lookup uses are the
        // same string.
        //
        // `RedirectIfOneTimePasswordAuthenticatable` then runs before
        // `AttemptToAuthenticate` and, when `OneTimePasswordFeature` is on,
        // never calls `$next()` — it replaces the password check rather than
        // adding a factor after it, so `AttemptToAuthenticate` never runs for
        // a request it has handled.
        Fortify::authenticateThrough(fn (Request $request): array => array_filter([
            Config::get('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class,
            Config::get('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
            OneTimePasswordFeature::available() ? RedirectIfOneTimePasswordAuthenticatable::class : null,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
            LogInToCentralGuard::class,
        ]));

        $this->registerAuthRateLimiters();

        // `VerifyEmailController` type-hints Fortify's own concrete
        // `VerifyEmailRequest`; binding a subclass still resolves for a
        // concrete type-hint, and it's the only way to compare against
        // `getGlobalIdentifierKey()` instead of the primary key.
        $this->app->bind(FortifyVerifyEmailRequest::class, NumerosisVerifyEmailRequest::class);
        $this->app->singleton(FortifyVerifyEmailResponse::class, NumerosisVerifyEmailResponse::class);
    }

    /**
     * Fortify's default `login` rate limiter keys on
     * `lower(username).'|'.$request->ip()` — one bucket across every tenant,
     * so a user at the same email address on two different tenants shares a
     * lockout counter, and tenant A's failed attempts lock out tenant B's
     * user. Mixing the tenant key into `->by(...)` is the fix; regression-test
     * it with two tenants; a single-tenant test passes either way.
     *
     * The OTP challenge gets its **own** limiter for a related reason: its
     * request carries no `email` field, so reusing `login` would key every
     * verification from one IP into a single bucket. It reads the address
     * back out of the session instead — the same value the send leg keyed on,
     * so the two legs stay per-user without the challenge form having to
     * carry (and therefore let a caller choose) the address.
     *
     * **Known gap, upstream:** `spatie/laravel-one-time-passwords` runs its
     * own per-user limiter inside `ConsumeOneTimePasswordAction`, keyed
     * `consume-one-time-password-attempt:{$user->getKey()}` — a primary key,
     * which collides across tenant databases and with the central users
     * table. Five wrong attempts against tenant A's user 1 also lock tenant
     * B's user 1 for the window. It is a denial of service, not a bypass, and
     * the limiter registered here is the one that actually bounds guessing;
     * fixing it means overriding a vendor action that this package only
     * `suggest`s, so it is recorded rather than forked.
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
    }

    /**
     * The address the OTP send leg stashed, which is what keys the challenge's
     * limiter — the challenge form deliberately does not carry it, so a
     * caller cannot choose whose bucket to spend.
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

<?php

declare(strict_types=1);

namespace Nvade\Numerosis;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Auth\AuthenticateLoginCandidate;
use Nvade\Numerosis\Actions\Auth\CreateRegisteredUser;
use Nvade\Numerosis\Actions\Auth\FindLoginCandidate;
use Nvade\Numerosis\Actions\Auth\ResolvePostLoginRedirectUrl;
use Nvade\Numerosis\Actions\Auth\SendEmailVerificationNotification;
use Nvade\Numerosis\Actions\Invitations\CreateInvitedUser;
use Nvade\Numerosis\Commands\InstallNumerosisCommand;
use Nvade\Numerosis\Commands\NumerosisCommand;
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
use Nvade\Numerosis\Contracts\Invitations\CreatesInvitedUser;
use Nvade\Numerosis\Events\Auth\SocialAccountConnected;
use Nvade\Numerosis\Events\Auth\SocialAccountDisconnected;
use Nvade\Numerosis\Events\Billing\PaymentFailed;
use Nvade\Numerosis\Events\Billing\PaymentSettled;
use Nvade\Numerosis\Events\Billing\TenantSuspended;
use Nvade\Numerosis\Events\Invitations\InvitationIssued;
use Nvade\Numerosis\Events\Modules\ModulePurchased;
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
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Numerosis;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class NumerosisServiceProvider extends PackageServiceProvider
{
    use PublishesPackageAssets;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('numerosis')
            ->hasConfigFile('numerosis')
            ->hasViews()
            ->hasTranslations()
            ->discoversMigrations(true, '/database/migrations/central')
            ->runsMigrations()
            ->hasCommand(NumerosisCommand::class)
            ->hasCommand(InstallNumerosisCommand::class)
            ->hasCommand(DeleteTenants::class)
            ->hasCommand(MigrateTenantModule::class)
            ->hasCommand(RollbackTenantModule::class)
            ->hasCommand(SeedTenantModule::class)
            ->hasCommand(PruneOrphanedStripeCustomers::class)
            ->hasCommand(PruneOrphanedTenantDatabases::class)
            ->hasCommand(PruneStalledTenantProvisions::class);
    }

    public function packageRegistered(): void
    {
        // TenancyServiceProvider and BillingServiceProvider merge their own
        // config/numerosis-{tenancy,billing}.php via mergeConfigFrom() in
        // their own register() — registering them here, rather than
        // hardcoding them into composer.json's extra.laravel.providers list
        // alongside this class, keeps them swappable the same way a
        // consumer can already replace any other package binding.
        $this->app->register(TenancyServiceProvider::class);
        $this->app->register(BillingServiceProvider::class);

        $this->app->bind(ResolvesLoginCandidate::class, FindLoginCandidate::class);
        $this->app->bind(AuthenticatesLoginCandidate::class, AuthenticateLoginCandidate::class);
        $this->app->bind(ResolvesPostLoginRedirectUrl::class, ResolvePostLoginRedirectUrl::class);
        $this->app->bind(CreatesRegisteredUser::class, CreateRegisteredUser::class);
        $this->app->bind(SendsEmailVerificationNotification::class, SendEmailVerificationNotification::class);
        $this->app->bind(CreatesInvitedUser::class, CreateInvitedUser::class);
    }

    public function packageBooted(): void
    {
        // RegistrationWizardFeature reads this to register its Livewire
        // components' view paths — see its own docblock. Set here rather
        // than left as the extraction-era base_path() default in
        // config/numerosis.php, now that views actually ship from the
        // package.
        Config::set('numerosis.views.path', __DIR__.'/../resources/views');

        // Laravel's default factory-name guesser rebuilds the factory class
        // under the *model's own* root namespace — correct for a
        // single-repo app, wrong once the model is a thin-app stub
        // (App\Models\Central\Tenant) whose factory actually lives in this
        // package. See Numerosis::factoryNameFor()'s docblock.
        Factory::guessFactoryNamesUsing(Numerosis::factoryNameFor(...));

        // The reverse direction: `Tenant::factory()` on a host-published stub
        // must build a `Tenant\TenantFactory` that yields the *stub*, not the
        // abstract package model — but a factory for a model with no stub
        // (`Membership`, `Role`, …) must still yield the package model
        // directly. See Numerosis::modelNameFor()'s docblock.
        Factory::guessModelNamesUsing(fn (Factory $factory): string => Numerosis::modelNameFor($factory::class));

        foreach (Features::all() as $feature) {
            $this->app->make($feature)->bootstrap();
        }

        $this->registerEventListeners();

        // Always-on, not behind RegistrationWizardFeature: the standalone
        // /checkout/{domain} route and the wizard's embedded
        // <livewire:billing.checkout /> both address this component by the
        // dotted name below, which Livewire's Finder can only resolve for
        // package classes when explicitly registered — same reason
        // RegistrationWizardFeature registers its own four step components.
        Livewire::addComponent(name: 'billing.checkout', class: Checkout::class);

        // `hasViews()` above registers resources/views under the `numerosis::`
        // namespace, which is not where Flux looks: `<flux:icon.x />` compiles
        // to a lookup in the anonymous-component namespace Flux registers with
        // `Blade::anonymousComponentPath(…, 'flux')`. Four of this package's
        // own views use Lucide icons Flux does not ship (`folder-git-2`,
        // `book-open-text`, `layout-grid`, `chevrons-up-down`), and
        // resources/views/flux/icon holds them — so without this line every
        // page rendering the header or sidebar dies with `Flux component
        // [icon.folder-git-2] does not exist`, from a vendor stub, naming
        // neither this package nor the view that asked. saas-m never saw it:
        // its copies sat in the app's own resource_path('views/flux'), which
        // is the first path Flux registers.
        //
        // Deferred to `booted()` so it lands *after* Flux's own two paths.
        // Registration order is resolution order, so the host's
        // resource_path('views/flux') still wins (a consumer can override any
        // of these), then Flux's stubs, then this — which means the stale
        // resources/views/flux/navlist/group.blade.php in here, a
        // Prettier-reformatted copy of Flux's own stub that quietly lost
        // `rtl:rotate-180`, stays unreachable rather than shadowing the real
        // component.
        $this->app->booted(function (): void {
            Blade::anonymousComponentPath(__DIR__.'/../resources/views/flux', 'flux');
        });

        // Tenant migrations are never auto-run centrally — stancl runs them
        // per-tenant via config('tenancy.migration_parameters'), which the
        // host must point at this absolute vendor path (see
        // docs/host-requirements.md). Only published here, so a consumer
        // can copy and customize them without vendor-patching.
        $this->publishGroup([
            __DIR__.'/../database/migrations/tenant' => database_path('migrations/tenant'),
        ], 'numerosis-tenant-migrations');

        $this->publishGroup([
            __DIR__.'/../resources/css' => resource_path('vendor/numerosis/css'),
            __DIR__.'/../resources/js' => resource_path('vendor/numerosis/js'),
        ], 'numerosis-assets');

        // The 9 concrete model stubs (see .claude/rules — 4.4's "abstract
        // base in the package, concrete in thin-app"). 'numerosis-models'
        // and 'numerosis-stubs' both point at the same file set: there is
        // nothing else stub-shaped in this package to give the second tag
        // a distinct meaning, and a consumer publishing either tag needs
        // to end up with the same 9 files regardless of which name they
        // used.
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
        $this->publishGroup($modelStubs, 'numerosis-stubs');
    }

    /**
     * Laravel's event discovery only ever scans the *host application's*
     * `app/Listeners`, so every listener this package ships was silently
     * unregistered once the code moved into `src/` — the events still fired
     * and still drove local state (a tenant really was suspended), only the
     * outbound side effect never happened. `BillingNotificationsFeature`'s
     * docblock still asserts these are "auto-discovered by Laravel's event
     * discovery"; that was true in the monolith and is false here.
     *
     * Registration is unconditional on purpose: each listener already gates
     * itself on its own feature with an early return inside `handle()`, which
     * is the arrangement those feature classes document. Gating here as well
     * would move the decision to boot time and silently change what
     * `Features::forceForTesting()` can still influence mid-request.
     *
     * Not listed here, because they are already registered elsewhere and
     * would fire twice: `SyncTenantToStripeOnSave`
     * (`BillingServiceProvider::configureStripeSync()`), `UpdateSyncedResource`
     * and `LogSyncedResourceChangedInForeignDatabase`
     * (`TenancyServiceProvider::events()`).
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
}

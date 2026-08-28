<?php

declare(strict_types=1);

use Nvade\Numerosis\Actions\Auth\AuthenticateLoginCandidate;
use Nvade\Numerosis\Actions\Auth\CreateRegisteredUser;
use Nvade\Numerosis\Actions\Auth\FindLoginCandidate;
use Nvade\Numerosis\Actions\Auth\ResolvePostLoginRedirectUrl;
use Nvade\Numerosis\Actions\Auth\SendEmailVerificationNotification;
use Nvade\Numerosis\Actions\Invitations\CreateInvitedUser;
use Nvade\Numerosis\Actions\Tenancy\AddTenantOwner;
use Nvade\Numerosis\Actions\Tenancy\CreateTenant as CreateTenantAction;
use Nvade\Numerosis\Actions\Tenancy\ProvisionTenant;
use Nvade\Numerosis\Contracts\Auth\AuthenticatesLoginCandidate;
use Nvade\Numerosis\Contracts\Auth\CreatesRegisteredUser;
use Nvade\Numerosis\Contracts\Auth\ResolvesLoginCandidate;
use Nvade\Numerosis\Contracts\Auth\ResolvesPostLoginRedirectUrl;
use Nvade\Numerosis\Contracts\Auth\SendsEmailVerificationNotification;
use Nvade\Numerosis\Contracts\Auth\SocialAccountRepository;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Contracts\Billing\ModuleCatalog;
use Nvade\Numerosis\Contracts\Billing\MoneyFormatter;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Contracts\Billing\PlanPolicy;
use Nvade\Numerosis\Contracts\Billing\SubscriptionRepository;
use Nvade\Numerosis\Contracts\Billing\TrialResolver;
use Nvade\Numerosis\Contracts\Billing\UnpaidTenantQuota;
use Nvade\Numerosis\Contracts\Invitations\CreatesInvitedUser;
use Nvade\Numerosis\Contracts\Invitations\InvitationRepository;
use Nvade\Numerosis\Contracts\Modules\ModuleRegistry;
use Nvade\Numerosis\Contracts\Notifications\NotifiesTenantOwner;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseManager;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Features\Auth\EmailVerificationFeature;
use Nvade\Numerosis\Features\Auth\PasswordResetFeature;
use Nvade\Numerosis\Features\Billing\BillingNotificationsFeature;
use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Features\Modules\ModuleSystemFeature;
use Nvade\Numerosis\Features\Observability\ActivityLogFeature;
use Nvade\Numerosis\Features\Social\SocialLoginFeature;
use Nvade\Numerosis\Features\Tenancy\ImpersonationFeature;
use Nvade\Numerosis\Features\Tenancy\MembershipsFeature;
use Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature;
use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Nvade\Numerosis\Features\Ui\AccountPagesFeature;
use Nvade\Numerosis\Features\Ui\AdminPanelFeature;
use Nvade\Numerosis\Features\Ui\MarketingPagesFeature;
use Nvade\Numerosis\Features\Ui\TenantPanelFeature;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\SubscriptionItem;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Invitation;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Services\Billing\Checkout\InlineCheckoutGateway;
use Nvade\Numerosis\Services\Billing\Modules\EloquentModuleCatalog;
use Nvade\Numerosis\Services\Billing\Plans\EloquentPaymentPlanRepository;
use Nvade\Numerosis\Services\Billing\Resolvers\CashierMoneyFormatter;
use Nvade\Numerosis\Services\Billing\Resolvers\DefaultUnpaidTenantQuota;
use Nvade\Numerosis\Services\Billing\Resolvers\PlanOrDefaultTrialResolver;
use Nvade\Numerosis\Services\Billing\Resolvers\SeatLimitPlanPolicy;
use Nvade\Numerosis\Services\Billing\Resolvers\TenantOrUserBillableResolver;
use Nvade\Numerosis\Services\Billing\Subscriptions\EloquentSubscriptionRepository;
use Nvade\Numerosis\Services\Tenancy\DefaultTenantDomainPolicy;
use Nvade\Numerosis\Support\Defaults\EloquentInvitationRepository;
use Nvade\Numerosis\Support\Defaults\EloquentModuleRegistry;
use Nvade\Numerosis\Support\Defaults\EloquentSocialAccountRepository;
use Nvade\Numerosis\Support\Defaults\NotifiesTenantOwnerDirectly;
use Nvade\Numerosis\Support\Defaults\StanclTenantDatabaseManager;

return [

    /*
    |--------------------------------------------------------------------------
    | Schema version
    |--------------------------------------------------------------------------
    |
    | `HostConfig::numerosisConfig()` deep-fills any *missing* key in a
    | host's published file, at every depth — but a key the host's file
    | still names, just with an older shape (a restructured array, a
    | renamed top-level key its file still carries the old name of), is
    | invisible to that fill: the key isn't missing, so nothing touches it.
    | `InstallNumerosisCommand::verifyConfigSchemaVersion()` reads this
    | value straight out of a *published* config/numerosis.php (not through
    | config(), which would already show the package's current default —
    | see that method's own docblock) and fails loudly when it's behind.
    |
    | Bump this in the same commit as any change to a top-level key's name
    | or shape — not for additions inside an existing keyed array, which the
    | deep-fill already covers safely.
    |
    */

    'schema_version' => 1,

    /*
    |--------------------------------------------------------------------------
    | Feature toggles
    |--------------------------------------------------------------------------
    */

    'features' => [

        // Requires TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY — see .env.example.
        TurnstileFeature::class,

        // OAuth login. Providers are configured above ('social') +
        // config/services.php (credentials). Discord's Socialite extension
        // registers itself when credentials are present — see
        // SocialLoginFeature::bootstrap().
        SocialLoginFeature::class,

        // The per-tenant module system, storefront included. Comment out to
        // run no modules at all.
        ModuleSystemFeature::class,

        // Team invitations. The invite/accept flow, InvitationResource, and
        // the invitation-sent notification.
        InvitationsFeature::class,

        // Self-serve tenant registration wizard (/get-started). Tenant
        // provisioning itself is unaffected — see the class docblock.
        RegistrationWizardFeature::class,

        // Not a real toggle — see the class docblock. Registered
        // unconditionally; do not comment out.
        EmailVerificationFeature::class,

        // Payment confirmed / payment failed / tenant suspended emails.
        BillingNotificationsFeature::class,

        // Password reset (login itself is passwordless). Does not cover
        // password.confirm — see the class docblock.
        PasswordResetFeature::class,

        // Third-party audit-log UI (AlizHarb\ActivityLog). Does not stop
        // spatie/laravel-activitylog from writing — see the class docblock.
        ActivityLogFeature::class,

        // This product's marketing pages — a package consumer replaces
        // these with their own. See the class docblock re: 'home'.
        MarketingPagesFeature::class,

        // This product's account UI. See the class docblock re: 'tenants.mine'.
        AccountPagesFeature::class,

        // The central admin panel (/admin). Gate lives in
        // AdminPanelProvider::register() — see the class docblock.
        AdminPanelFeature::class,

        // The tenant admin panel ({tenant}.<domain>/). Gate lives in
        // TenantAdminPanelProvider::register() — see the class docblock.
        TenantPanelFeature::class,

        // Tenant membership UI (Team cluster / Users resource). Does not
        // gate InvitationsFeature — see the class docblock.
        MembershipsFeature::class,

        // "Impersonate owner" on the central Tenants table — opens a real
        // session as the tenant's owner, for support. Security-sensitive:
        // remove this if central-panel staff should not be able to do that.
        ImpersonationFeature::class,

    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled command switches
    |--------------------------------------------------------------------------
    |
    | Booleans, not feature classes — a cron entry is an operations surface,
    | not an app feature. "Does this deployment's cron run this command" is an
    | operations question, not a product one. telescope:prune is gated
    | separately, by class_exists — not a key here, since it has exactly one
    | owner already.
    |
    | Read by NumerosisServiceProvider::registerSchedule(), which the package
    | registers itself. A host does not (and must not) load a routes file for
    | these: see that method's docblock for the consumer-invisible bug that
    | arrangement caused.
    |
    */

    'schedule' => [
        'prune_orphaned_customers' => (bool) env('SCHEDULE_PRUNE_ORPHANED_CUSTOMERS', true),
        'prune_stalled_provisions' => (bool) env('SCHEDULE_PRUNE_STALLED_PROVISIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route name indirection
    |--------------------------------------------------------------------------
    |
    | 'home' and 'tenants.mine' are called from ~20 sites outside their own
    | route file (see Nvade\Numerosis\Support\Routes\RouteNames). Phase 8 of the
    | opt-in-feature-classes plan gates the routes that register these two
    | names, so every caller reads the name from here instead of a literal
    | string — a disabled feature that renamed or removed the route would
    | otherwise turn each of those call sites into a RouteNotFoundException.
    | Not the full route-name indirection package-extraction.md describes —
    | scoped to only the two names this phase actually gates.
    |
    */

    'routes' => [
        'names' => [
            'home' => 'home',
            'tenants_mine' => 'tenants.mine',
            'invitation_show' => 'invitation.show',
            'checkout_subscription' => 'checkout.subscription',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain configuration
    |--------------------------------------------------------------------------
    |
    | These four keys used to live in the host's own config/app.php, as
    | `app.domain`, `app.host` and `app.central.{domain,default,subdomain}`.
    | That was a mistake with a specific cost: config/app.php is a framework
    | file, so this package could not `mergeConfigFrom()` a default into it —
    | which meant every consumer had to hand-edit Laravel's own config, and
    | numerosis:install had to *check* for the keys and explain the failure
    | signature rather than simply supplying a value.
    |
    | It also let two keys drift. `app.domain` derived from env('DOMAIN')
    | while `app.host` derived from env('DOMAIN_NAME').'.'.env('DOMAIN_EXTENSION'),
    | and Domain::getUrl() read the second while CreateTenantDomain and
    | DefaultTenantDomainPolicy read the first — so a tenant's stored domain
    | and its generated URL could name different hosts, with nothing
    | comparing them.
    |
    | Every value below defaults off APP_URL, so a host that sets nothing at
    | all still boots. `apex` is the registrable domain tenant subdomains hang
    | off; `central` is the hostname the central app itself answers on.
    |
    */

    'domains' => [
        // The bare domain tenant subdomains are created under, e.g.
        // "example.com" for "acme.example.com". Defaults to APP_URL's host
        // with its leading label stripped *only* when a central subdomain is
        // configured — otherwise APP_URL's host already is the apex.
        'apex' => env('NUMEROSIS_APEX_DOMAIN') ?: Nvade\Numerosis\Support\Domains::apexFromAppUrl(),

        // The hostname the central app answers on — what routes/auth.php's
        // OAuth routes are bound to. Distinct from `apex`: a deployment may
        // serve the central app from a subdomain (app.example.com) while
        // tenants live directly under the apex.
        'central' => env('NUMEROSIS_CENTRAL_DOMAIN') ?: Nvade\Numerosis\Support\Domains::hostFromAppUrl(),

        // '{tenant}.'.apex, not APP_URL's host — APP_URL may carry the
        // central subdomain too (e.g. app.example.com), which would put every
        // tenant one level too deep. Matches config('tenancy.tenant_domain').
        'tenant_pattern' => env('NUMEROSIS_TENANT_DOMAIN')
            ?: '{tenant}.'.(env('NUMEROSIS_APEX_DOMAIN') ?: Nvade\Numerosis\Support\Domains::apexFromAppUrl()),
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcasting
    |--------------------------------------------------------------------------
    |
    | The browser's Reverb connection details — read here, at request time,
    | rather than baked into resources/js/numerosis.js (formerly two
    | separate files, central.js/tenant.js) as import.meta.env.VITE_REVERB_*
    | at *build* time. That used to mean a
    | prebuilt copy of that file was impossible: whatever Reverb host the
    | package maintainer's machine had would be frozen into the JS forever,
    | wrong for every consumer. See resources/views/partials/script-config.blade.php,
    | which is what actually reads this.
    |
    | 'key' is public (Pusher-protocol client key, not REVERB_APP_SECRET) —
    | safe to ship to the browser, same as it always was via Vite's env
    | inlining.
    |
    | host/port/scheme default off the same REVERB_* values
    | config('broadcasting.connections.reverb') reads — correct for a simple,
    | unproxied setup where the browser reaches Reverb directly. A host
    | fronting Reverb with a reverse proxy (thin-app's `ws.<domain>`, TLS
    | terminated before the Reverb process ever sees it) needs the browser to
    | connect somewhere different from where Reverb itself binds — that's
    | what NUMEROSIS_BROADCAST_HOST/PORT/SCHEME are for; they replace the old
    | VITE_REVERB_HOST/PORT/SCHEME env vars 1:1, just read server-side now
    | instead of by Vite.
    |
    */

    'broadcasting' => [
        'reverb' => [
            'key' => env('REVERB_APP_KEY'),
            'host' => env('NUMEROSIS_BROADCAST_HOST', env('REVERB_HOST', 'localhost')),
            'port' => (int) env('NUMEROSIS_BROADCAST_PORT', env('REVERB_PORT', 8080)),
            'scheme' => env('NUMEROSIS_BROADCAST_SCHEME', env('REVERB_SCHEME', 'http')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth
    |--------------------------------------------------------------------------
    |
    | 'guards' used to live at auth.defaults.guards.context.* — a package key
    | inside a framework config file, which meant no package default was
    | possible (config/auth.php can't be merged one level deep the way
    | config/numerosis.php is) and every consumer had to hand-write both
    | entries before login worked at all. 'central' names the guard the
    | central app authenticates on; 'tenant' the guard every tenant
    | subdomain uses. See Nvade\Numerosis\Enums\Tenancy\Context::guard().
    |
    | 'verification_expire' is minutes a signed email-verification link stays
    | valid for. Previously auth.verification.expire, same reasoning.
    |
    */

    'auth' => [
        'guards' => [
            'central' => 'web',
            'tenant' => 'tenant',
        ],

        'verification_expire' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Social Login
    |--------------------------------------------------------------------------
    |
    | Previously auth.social.* — see 'auth' above for why that was a mistake.
    | 'providers' is button label/icon metadata for every provider this
    | package knows how to render a button for; it is not the source of
    | truth for which are actually usable — Nvade\Numerosis\Support\Social\
    | ConfiguredProviders::all() intersects this list against config('services')
    | credentials, so a consumer with no OAuth app configured for a provider
    | simply never sees its button, no override needed here. 'routes' names
    | the two routes routes/auth.php registers for the OAuth redirect/callback
    | — the package owns those routes, so it owns the names.
    |
    */

    'social' => [
        'routes' => [
            'login' => ['name' => 'oauth.callback'],
            'redirect' => ['name' => 'oauth'],
        ],

        'providers' => [
            'google' => [
                'label' => 'Google',
                'hover' => 'hover:bg-blue-500/10 dark:hover:bg-blue-400/15',
                'icon' => 'heroicon-o-globe-alt',
            ],
            'github' => [
                'label' => 'GitHub',
                'hover' => 'hover:bg-gray-500/10 dark:hover:bg-gray-400/15',
                'icon' => 'heroicon-o-code-bracket',
            ],
            'discord' => [
                'label' => 'Discord',
                'hover' => 'hover:bg-indigo-500/10 dark:hover:bg-indigo-400/15',
                'icon' => 'heroicon-o-chat-bubble-left-right',
            ],
            'facebook' => [
                'label' => 'Facebook',
                'hover' => 'hover:bg-blue-500/10 dark:hover:bg-blue-400/15',
                'icon' => 'heroicon-o-globe-alt',
            ],
            'gitlab' => [
                'label' => 'GitLab',
                'hover' => 'hover:bg-orange-500/10 dark:hover:bg-orange-400/15',
                'icon' => 'heroicon-o-code-bracket',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Filament Panel Navigation
    |--------------------------------------------------------------------------
    |
    | Previously read straight off permission.filament.* — a key spatie/
    | laravel-permission's own published config does not define, so it had no
    | package default either. 'navigation_group' is the shared group both
    | Roles and Permissions resources sit under; each resource's own
    | 'navigation_group' overrides it when set, matching the previous
    | fallback chain in PermissionResource/RoleResource.
    |
    */

    'panels' => [
        'access_control' => [
            'navigation_group' => 'Access Control',

            'permissions' => [
                'navigation_group' => null,
                'navigation_label' => 'Permissions',
            ],

            'roles' => [
                'navigation_group' => null,
                'navigation_label' => 'Roles',
            ],
        ],

        // Which of the package's two panels Filament treats as the
        // application default (the one a bare '/' resolves into). 'admin' |
        // 'tenant' | null — null registers neither as default, which is only
        // safe if a host's own panel provider supplies one, since Filament
        // otherwise has no panel to route an unscoped request to.
        //
        // 'provider' lets a host replace either package panel provider
        // (Nvade\Numerosis\Providers\Filament\NumerosisAdminPanelProvider /
        // NumerosisTenantPanelProvider) with its own class entirely — see
        // NumerosisServiceProvider::registerFilamentPanels(). Left null, the
        // package's own provider registers, gated the same way it always
        // was: AdminPanelFeature/TenantPanelFeature via each plugin's own
        // shouldRegisterPanel().
        'default' => 'admin',
        'admin' => ['provider' => null],
        'tenant' => ['provider' => null],
    ],

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    |
    | Both keys default to empty, which is the whole point: this package ships
    | the module *system*, never a module. A host with no modules needs to set
    | nothing, and package code reading these no longer depends on a
    | host-owned `config/modules.php` existing at all — it used to, with no
    | package default, so `Config::array('modules.plugins')` threw for any
    | consumer that had not hand-created that file.
    |
    | 'catalogue' seeds the central `modules` table (see
    | Database\Seeders\Central\ModuleOfferingSeeder). Prices are minor
    | currency units (cents); Stripe price ids belong in env so test and live
    | can differ without editing config. A recurring module must carry both
    | monthly_id and yearly_id — Stripe rejects a subscription whose items do
    | not share a billing interval, so ModuleForm requires both.
    |
    | 'plugins' maps a module slug to its Filament plugin class, which
    | NumerosisTenantPlugin registers only for tenants that have the module
    | enabled. Core holds no reference to any concrete module.
    |
    */

    'modules' => [
        'catalogue' => [],

        'plugins' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Views
    |--------------------------------------------------------------------------
    |
    | Base path for package views. Used during package extraction phase;
    | after split, NumerosisServiceProvider::loadViewsFrom() supplies this.
    |
    */

    'views' => [
        'path' => base_path('resources/views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Prefix for every key in Nvade\Numerosis\Support\Cache\CacheKeys. Does not change
    | which keys are tenant-scoped (Cache::) vs global (global_cache()) —
    | see .claude/rules/tenant-caching.md.
    |
    */

    'cache' => [
        'prefix' => env('NUMEROSIS_CACHE_PREFIX', 'numerosis'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model overrides
    |--------------------------------------------------------------------------
    |
    | Every package call site that touches one of these 9 models resolves it
    | through `Numerosis::model()`. Left `null` (the default a host never has
    | to touch), that method still checks for a subclass named
    | `App\Models\<suffix>` — the same location `artisan vendor:publish
    | --tag=numerosis-models` writes its stub to — and uses it automatically
    | when found, no key here required. This array stays as the *explicit*
    | override for the rare case of a subclass living somewhere other than
    | the conventional path; see `Numerosis::model()`'s own docblock for the
    | full three-step resolution order.
    |
    */

    'models' => [
        Tenant::class => null,
        Domain::class => null,
        CentralUser::class => null,
        Subscription::class => null,
        PaymentPlan::class => null,
        PendingTenantProvision::class => null,
        Invitation::class => null,
        Module::class => null,
        TenantUser::class => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | Nested under its own key rather than merged flat: this array's 'models'
    | (Cashier customer/subscription model bindings) is a different shape
    | from the top-level 'models' key above (per-model class overrides), and
    | 'implementations' exists in both this section and 'tenancy' below —
    | flattening either would collide.
    |
    */

    'billing' => [

        /*
        |----------------------------------------------------------------------
        | Billable Models
        |----------------------------------------------------------------------
        |
        | The Cashier customer/subscription models this application uses. Swap
        | these to point Cashier at your own subclasses without touching the
        | service provider.
        |
        */

        'models' => [
            'tenant' => Tenant::class,
            'subscription' => Subscription::class,
            'subscription_item' => SubscriptionItem::class,
        ],

        /*
        |----------------------------------------------------------------------
        | Webhook Path
        |----------------------------------------------------------------------
        */

        'webhook_path' => env('BILLING_WEBHOOK_PATH', 'billing/webhook'),

        /*
        |----------------------------------------------------------------------
        | Default Trial Length
        |----------------------------------------------------------------------
        |
        | Used by PlanOrDefaultTrialResolver when a plan does not declare its
        | own trial_days.
        |
        */

        'trial_days' => 14,

        /*
        |----------------------------------------------------------------------
        | Subscription Plans
        |----------------------------------------------------------------------
        |
        | Backs ConfigPaymentPlanRepository, the zero-migration quickstart plan
        | source — bind it under 'implementations' below to use it. The
        | default EloquentPaymentPlanRepository reads from the payment_plans
        | table instead (seeded from these same values by PaymentPlanSeeder).
        |
        */

        'plans' => [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'short_description' => 'Perfect for small teams getting started.',
                'monthly_id' => env('STRIPE_STARTER_MONTHLY_PLAN', ''),
                'yearly_id' => env('STRIPE_STARTER_YEARLY_PLAN', ''),
                'yearly_incentive' => 'Save 20%',
                'trial_days' => 14,
                'features' => [
                    'Up to 5 team members',
                    'Basic features',
                    'Email support',
                    '--Priority support',
                    '--Advanced analytics',
                    '--Custom integrations',
                ],
                'options' => [
                    'max_users' => 5,
                    'max_storage_gb' => 10,
                    'priority_support' => false,
                    'custom_domain' => false,
                    'api_access' => false,
                ],
                'archived' => false,
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'short_description' => 'For growing teams that need more power.',
                'monthly_id' => env('STRIPE_PROFESSIONAL_MONTHLY_PLAN', ''),
                'yearly_id' => env('STRIPE_PROFESSIONAL_YEARLY_PLAN', ''),
                'monthly_incentive' => 'Most Popular',
                'yearly_incentive' => 'Save 20%',
                'trial_days' => 0,
                'features' => [
                    'Up to 20 team members',
                    'All basic features',
                    'Priority email support',
                    'Advanced analytics',
                    'Custom domain',
                    '--Custom integrations',
                ],
                'options' => [
                    'max_users' => 20,
                    'max_storage_gb' => 50,
                    'priority_support' => true,
                    'custom_domain' => true,
                    'api_access' => true,
                ],
                'archived' => false,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'short_description' => 'Unlimited power for large organizations.',
                'monthly_id' => env('STRIPE_ENTERPRISE_MONTHLY_PLAN', ''),
                'yearly_id' => env('STRIPE_ENTERPRISE_YEARLY_PLAN', ''),
                'yearly_incentive' => 'Save 25%',
                'trial_days' => 0,
                'features' => [
                    'Unlimited team members',
                    'All professional features',
                    '24/7 priority support',
                    'Advanced analytics & reporting',
                    'Custom domain',
                    'Custom integrations',
                    'Dedicated account manager',
                    'SLA guarantee',
                ],
                'options' => [
                    'max_users' => null, // Unlimited
                    'max_storage_gb' => 500,
                    'priority_support' => true,
                    'custom_domain' => true,
                    'api_access' => true,
                    'dedicated_support' => true,
                    'sla' => true,
                ],
                'archived' => false,
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Contract Bindings
        |----------------------------------------------------------------------
        |
        | Swap any of these for your own implementation by changing the class
        | here — no provider edit required. PlanPolicy, TrialResolver,
        | BillableResolver and MoneyFormatter also accept a closure override
        | via Billing::resolve*Using(), checked before this binding.
        |
        */

        'implementations' => [
            CheckoutGateway::class => InlineCheckoutGateway::class,
            PaymentPlanRepository::class => EloquentPaymentPlanRepository::class,
            ModuleCatalog::class => EloquentModuleCatalog::class,
            SubscriptionRepository::class => EloquentSubscriptionRepository::class,
            BillableResolver::class => TenantOrUserBillableResolver::class,
            PlanPolicy::class => SeatLimitPlanPolicy::class,
            TrialResolver::class => PlanOrDefaultTrialResolver::class,
            MoneyFormatter::class => CashierMoneyFormatter::class,
            UnpaidTenantQuota::class => DefaultUnpaidTenantQuota::class,
        ],

        /*
        |----------------------------------------------------------------------
        | Unpaid Tenant Cap
        |----------------------------------------------------------------------
        |
        | Provisioning happens before settlement (trials collect zero money
        | upfront), so this caps how many concurrently-unpaid tenants a single
        | user can own before StartSubscriptionCheckout refuses to start
        | another.
        |
        */

        'unpaid_tenant_cap' => env('BILLING_UNPAID_TENANT_CAP', 2),

        /*
        |----------------------------------------------------------------------
        | Stripe Customer Sync
        |----------------------------------------------------------------------
        |
        | Disable if your application syncs tenants to Stripe itself and does
        | not want SyncTenantToStripe firing a second write on every
        | TenantSaved.
        |
        */

        'sync' => [
            'stripe_customer' => true,
        ],

        /*
        |----------------------------------------------------------------------
        | Checkout Payment Method Order
        |----------------------------------------------------------------------
        |
        | Display order only — Stripe still decides which methods are
        | actually eligible (currency, amount, account country), this only
        | reorders what it was already going to show. A method absent from a
        | region's list still appears, just after the curated ones; nothing
        | here restricts eligibility, only ResolveCheckoutRegion's country
        | lookup feeds the pick. See .claude/plans/checkout-region-localization.md.
        |
        */

        'payment_methods' => [
            'default_order' => ['card', 'link'],
            'regions' => [
                'NL' => ['ideal', 'card', 'bancontact', 'sepa_debit', 'link'],
                'BE' => ['bancontact', 'card', 'ideal', 'sepa_debit', 'link'],
                'DE' => ['card', 'sepa_debit', 'giropay', 'link'],
                'AT' => ['card', 'sepa_debit', 'eps', 'link'],
                'FR' => ['card', 'sepa_debit', 'link'],
                'ES' => ['card', 'sepa_debit', 'link'],
                'IT' => ['card', 'sepa_debit', 'link'],
                'GB' => ['card', 'link'],
                'US' => ['card', 'link'],
                'PL' => ['card', 'blik', 'link'],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    */

    'tenancy' => [

        /*
        |----------------------------------------------------------------------
        | Tenant Provisioning Pipeline
        |----------------------------------------------------------------------
        |
        | Steps run in order, as links in ProvisionTenant's queued chain on
        | the `provisioning` queue, every time a tenant is provisioned. The
        | first entry must create/find the tenant and return it — every step
        | after that (including any you append) is invoked as
        | ::run(Tenant $tenant, TenantProvisionData $data).
        | LinkTenantSubscription (only when a Stripe subscription id is
        | present) and FinalizeTenantProvisioning are appended automatically
        | after these — they cannot be reordered here, because
        | FinalizeTenantProvisioning is the sole emitter of the "provisioning
        | finished" signal. Every step must be idempotent: the whole list
        | re-runs on retry.
        |
        | This is the one place business-level provisioning order is defined.
        | The separate physical-database segment (create/migrate/seed the
        | tenant database) lives in
        | TenancyServiceProvider::$tenantCreatedJobs — kept apart because
        | those are raw queue jobs constructed `new $job($tenant)`, not
        | AsAction steps invoked `::run($tenant, $data)`, and because tests
        | override that list to swap in a fast template-clone stand-in. See
        | .claude/rules/tenant-provisioning.md.
        |
        */

        'provisioning' => [
            'steps' => [
                CreateTenantAction::class,
                AddTenantOwner::class,
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Domain-to-Tenant Resolver Cache
        |----------------------------------------------------------------------
        |
        | Caches the domain lookup every tenant request would otherwise pay
        | against the central database. Leave null to follow what your cache
        | store can actually hold: the resolver caches a tenant *model*, and
        | a fresh Laravel app's `cache.serializable_classes => false` turns
        | any cached object into __PHP_Incomplete_Class on read — silently —
        | so the cache stays off unless that config allows the tenant model
        | through. true or false decides it yourself; see
        | TenancyServiceProvider::shouldCacheResolvedTenants().
        |
        */

        'cache_resolved_tenants' => null,

        /*
        |----------------------------------------------------------------------
        | Contract Bindings
        |----------------------------------------------------------------------
        |
        | Swap any of these for your own implementation by changing the class
        | here — no provider edit required.
        |
        */

        'implementations' => [
            TenantDomainPolicy::class => DefaultTenantDomainPolicy::class,
            ProvisionsTenant::class => ProvisionTenant::class,
            TenantDatabaseManager::class => StanclTenantDatabaseManager::class,
            InvitationRepository::class => EloquentInvitationRepository::class,
            ModuleRegistry::class => EloquentModuleRegistry::class,
            SocialAccountRepository::class => EloquentSocialAccountRepository::class,
            NotifiesTenantOwner::class => NotifiesTenantOwnerDirectly::class,
            // Auth/invitation contracts: previously bound only in saas-m's
            // AppServiceProvider, which stays in thin-app per the extraction
            // plan's "app-specific bindings" table — but these back the
            // package's own PasswordlessLogin/Register/Accept Livewire
            // components, so an unbound default leaves the package broken
            // for any consumer until they re-derive this list by hand.
            ResolvesLoginCandidate::class => FindLoginCandidate::class,
            AuthenticatesLoginCandidate::class => AuthenticateLoginCandidate::class,
            ResolvesPostLoginRedirectUrl::class => ResolvePostLoginRedirectUrl::class,
            CreatesRegisteredUser::class => CreateRegisteredUser::class,
            SendsEmailVerificationNotification::class => SendEmailVerificationNotification::class,
            CreatesInvitedUser::class => CreateInvitedUser::class,
        ],

    ],

];

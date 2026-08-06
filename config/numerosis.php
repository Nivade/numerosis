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
    | Feature toggles
    |--------------------------------------------------------------------------
    */

    'features' => [

        // Requires TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY — see .env.example.
        TurnstileFeature::class,

        // OAuth login. Providers are configured in config/auth.php
        // (auth.social.providers) + config/services.php (credentials).
        // Discord's Socialite extension registers itself when credentials
        // are present — see SocialLoginFeature::bootstrap().
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
    | through `Numerosis::model()` rather than referencing the class
    | literally, so a host wanting its own subclass (extra columns,
    | relationships, methods) sets one key here instead of editing call
    | sites. Left `null`, each resolves to the package's own concrete class
    | — the package models are not abstract, so this is a pure override, not
    | a requirement to publish a stub before anything runs.
    |
    */

    'models' => [
        Tenant::class => env('NUMEROSIS_MODEL_TENANT'),
        Domain::class => env('NUMEROSIS_MODEL_DOMAIN'),
        CentralUser::class => env('NUMEROSIS_MODEL_CENTRAL_USER'),
        Subscription::class => env('NUMEROSIS_MODEL_SUBSCRIPTION'),
        PaymentPlan::class => env('NUMEROSIS_MODEL_PAYMENT_PLAN'),
        PendingTenantProvision::class => env('NUMEROSIS_MODEL_PENDING_TENANT_PROVISION'),
        Invitation::class => env('NUMEROSIS_MODEL_INVITATION'),
        Module::class => env('NUMEROSIS_MODEL_MODULE'),
        TenantUser::class => env('NUMEROSIS_MODEL_TENANT_USER'),
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

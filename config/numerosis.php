<?php

declare(strict_types=1);

use Nvade\Numerosis\Actions\Auth\AuthenticateLoginCandidate;
use Nvade\Numerosis\Actions\Auth\ResolveLoginCandidate;
use Nvade\Numerosis\Actions\Auth\SendEmailVerificationNotification;
use Nvade\Numerosis\Actions\Tenancy\AddTenantOwner;
use Nvade\Numerosis\Actions\Tenancy\CreateTenant as CreateTenantAction;
use Nvade\Numerosis\Actions\Tenancy\CreateTenantDatabase;
use Nvade\Numerosis\Actions\Tenancy\FinalizeTenantProvisioning;
use Nvade\Numerosis\Actions\Tenancy\LinkTenantSubscription;
use Nvade\Numerosis\Actions\Tenancy\MigrateTenantDatabase;
use Nvade\Numerosis\Actions\Tenancy\PromoteFirstUserToAdmin;
use Nvade\Numerosis\Actions\Tenancy\ProvisionTenant;
use Nvade\Numerosis\Actions\Tenancy\SeedTenantDatabase;
use Nvade\Numerosis\Boot\Domains;
use Nvade\Numerosis\Contracts\Auth\AuthenticatesLoginCandidate;
use Nvade\Numerosis\Contracts\Auth\ResolvesLoginCandidate;
use Nvade\Numerosis\Contracts\Auth\SendsEmailVerificationNotification;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Contracts\Billing\CheckoutRegionResolver;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Contracts\Billing\PlanPolicy;
use Nvade\Numerosis\Contracts\Billing\SubscriptionRepository;
use Nvade\Numerosis\Contracts\Billing\TrialResolver;
use Nvade\Numerosis\Contracts\Billing\UnpaidTenantQuota;
use Nvade\Numerosis\Contracts\Notifications\NotifiesTenantOwner;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseManager;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Database\Seeders\TenantDatabaseSeeder;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Features\Auth\EmailVerificationFeature;
use Nvade\Numerosis\Features\Auth\PasswordResetFeature;
use Nvade\Numerosis\Features\Auth\SocialLoginFeature;
use Nvade\Numerosis\Features\Billing\BillingNotificationsFeature;
use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature;
use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Central\OwnershipNomination;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\SocialAccount;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\SubscriptionItem;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Services\Billing\DefaultUnpaidTenantQuota;
use Nvade\Numerosis\Services\Billing\EloquentPaymentPlanRepository;
use Nvade\Numerosis\Services\Billing\EloquentSubscriptionRepository;
use Nvade\Numerosis\Services\Billing\InlineCheckoutGateway;
use Nvade\Numerosis\Services\Billing\NullCheckoutRegionResolver;
use Nvade\Numerosis\Services\Billing\PlanOrDefaultTrialResolver;
use Nvade\Numerosis\Services\Billing\SeatLimitPlanPolicy;
use Nvade\Numerosis\Services\Billing\TenantOrUserBillableResolver;
use Nvade\Numerosis\Services\Notifications\NotifiesTenantOwnerDirectly;
use Nvade\Numerosis\Services\Tenancy\DefaultTenantDomainPolicy;
use Nvade\Numerosis\Services\Tenancy\StanclTenantDatabaseManager;

$apex = env('NUMEROSIS_APEX_DOMAIN') ?: Domains::apexFromAppUrl();

/**
 * Publishable. `HostConfig`'s deep-fill backfills every key a host's copy
 * omits, at any depth, so an override file only has to name what it changes.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Feature toggles
    |--------------------------------------------------------------------------
    */

    'features' => [
        // Requires TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY.
        TurnstileFeature::class,

        // OAuth login: provider buttons, connected-accounts, the callback
        // route. Needs credentials in config/services.php; which providers
        // exist is Enums\Auth\SocialProvider, not config.
        SocialLoginFeature::class,

        // Team invitations: the invite/accept flow and the invitation-sent
        // notification.
        InvitationsFeature::class,

        // Self-serve tenant registration wizard (/get-started). Tenant
        // provisioning itself is unaffected.
        RegistrationWizardFeature::class,

        // Not a real toggle. Registered unconditionally; do not comment out.
        EmailVerificationFeature::class,

        // Payment confirmed / payment failed / tenant suspended emails.
        BillingNotificationsFeature::class,

        // Password reset. Fortify's own password.confirm route is
        // unaffected.
        PasswordResetFeature::class,

        // Passwordless email OTP login, layered on Fortify rather than
        // replacing it. Off by default.
        // \Nvade\Numerosis\Features\Auth\OneTimePasswordFeature::class,

        // Staff screens on the central domain, under
        // 'routes.staff_prefix'. Off by default.
        // \Nvade\Numerosis\Features\Admin\StaffPanelFeature::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled command switches
    |--------------------------------------------------------------------------
    |
    | Booleans, not feature classes: whether this deployment's cron runs a
    | given prune command, an operations decision rather than a product one.
    */

    'schedule' => [
        'prune_orphaned_customers' => (bool) env('SCHEDULE_PRUNE_ORPHANED_CUSTOMERS', true),

        // Drops tenant databases with no tenant row, deletes tenants
        // suspended past the cutoff, and — only when
        // numerosis.tenancy.closure.purge_closed is on as well — purges
        // closed ones. Off by default: nothing here is recoverable.
        'prune_orphaned_databases' => (bool) env('SCHEDULE_PRUNE_ORPHANED_DATABASES', false),

        'prune_stalled_provisions' => (bool) env('SCHEDULE_PRUNE_STALLED_PROVISIONS', true),
        'prune_invitations' => (bool) env('SCHEDULE_PRUNE_INVITATIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Only read by the un-wired fallback boot (an app whose bootstrap/app.php
    | never called Numerosis::middleware() itself) — see docs/host-requirements.md.
    | Empty trusts nobody, Laravel's own default: X-Forwarded-* headers are
    | ignored, and $request->ip() is the real socket peer. Set proxy IP(s)/CIDR
    | here, or the literal '*' to trust every request's forwarded headers.
    |
    | '*' is only safe when nothing but your proxy can reach the app directly
    | (a PaaS edge, a firewalled load balancer) — otherwise anyone hitting the
    | app directly can forge X-Forwarded-For and spoof $request->ip(), which
    | defeats every IP-keyed rate limiter (including this package's own login
    | throttle) and falsifies audit logs.
    */

    'trusted_proxies' => env('TRUSTED_PROXIES') === '*'
        ? '*'
        : array_values(array_filter(explode(',', (string) env('TRUSTED_PROXIES', '')), static fn (string $ip): bool => $ip !== '')),

    /*
    |--------------------------------------------------------------------------
    | Route name indirection
    |--------------------------------------------------------------------------
    |
    | Names read from here rather than hardcoded, since the routes behind
    | 'home' and 'tenants_mine' are feature-gated and callers outside their
    | own route file would otherwise break if one were renamed or disabled.
    */

    'routes' => [
        'names' => [
            'home' => 'home',
            'tenants_mine' => 'tenants.mine',
            'invitation_show' => 'invitations.show',
            'invitation_accept' => 'invitations.accept',
            'ownership_nomination_show' => 'ownership.nominations.show',
            'ownership_nomination_accept' => 'ownership.nominations.accept',
            'checkout_subscription' => 'checkout.subscription',
        ],

        // Core registers 'home' unconditionally. Point this at your own view
        // to keep core's route, or declare `/` in your own `routes/web.php`,
        // which is loaded afterwards and replaces it.
        'home_view' => 'numerosis::home',

        // Where StaffPanelFeature's screens live. Not 'admin': hosts use that
        // path for their own product.
        'staff_prefix' => env('NUMEROSIS_STAFF_PREFIX', 'staff'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain configuration
    |--------------------------------------------------------------------------
    |
    | Every value defaults off APP_URL, so a host that sets nothing still
    | boots. 'apex' is the registrable domain tenant subdomains hang off;
    | 'central' is the hostname the central app itself answers on.
    */

    'domains' => [
        'apex' => $apex,

        'central' => env('NUMEROSIS_CENTRAL_DOMAIN') ?: Domains::hostFromAppUrl(),

        // '{tenant}.'.apex, not APP_URL's host — APP_URL may carry a central
        // subdomain too, which would put every tenant one level too deep.
        'tenant_pattern' => env('NUMEROSIS_TENANT_DOMAIN') ?: '{tenant}.'.$apex,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth
    |--------------------------------------------------------------------------
    |
    | 'guards.central'/'guards.tenant' name the guard each side of tenancy
    | authenticates on. 'password_brokers.tenant' names the auth.passwords.*
    | entry Fortify's password-reset controllers use while tenancy is
    | initialized; there is no 'central' counterpart because outside tenancy
    | the broker is Fortify's own config('fortify.passwords').
    */

    'auth' => [
        'guards' => [
            'central' => 'web',
            'tenant' => 'tenant',
        ],

        'password_brokers' => [
            'tenant' => 'tenant',
        ],

        'verification_expire' => 60,

        // Whether HostConfig may write fortify.features. Set false once you
        // have edited that key yourself.
        'manage_fortify_features' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Social Login
    |--------------------------------------------------------------------------
    |
    | Route names only — provider metadata (label, icon, whether configured)
    | lives on Nvade\Numerosis\Enums\Auth\SocialProvider.
    */

    'social' => [
        'routes' => [
            'redirect' => ['name' => 'social.redirect'],
            'callback' => ['name' => 'social.callback'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Prefix for every key in Nvade\Numerosis\Cache\CacheKeys. Does
    | not decide which keys are tenant-scoped vs global.
    |
    | 'store' is the cache store every global key is written to; null uses the
    | default store. Each 'ttl' is a number of seconds, keyed by the same name
    | as its CacheKeys method; null disables caching for that key.
    */

    'cache' => [
        'prefix' => env('NUMEROSIS_CACHE_PREFIX', 'numerosis'),

        'store' => env('NUMEROSIS_CACHE_STORE'),

        'ttl' => [
            'user_tenants' => 3600,
            'user_model' => 3600,
            'tenant_primary_domain' => 3600,
            'tenant_custom_columns' => 86400,
            'tenant_owner_global_id' => 3600,
            'available_payment_plans' => 3600,
            'popular_payment_plan_slug' => 300,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model overrides
    |--------------------------------------------------------------------------
    |
    | Every package call site resolves one of these through
    | Numerosis::model(). Left null, that method still finds a subclass named
    | App\Models\<suffix> automatically; set a key here only for a subclass
    | living somewhere else.
    */

    'models' => [
        Tenant::class => null,
        Domain::class => null,
        CentralUser::class => null,
        Subscription::class => null,
        PaymentPlan::class => null,
        TenantProvision::class => null,
        TenantUser::class => null,
        Invitation::class => null,
        OwnershipNomination::class => null,
        SocialAccount::class => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    */

    'billing' => [

        // The Cashier customer/subscription models this application uses.
        'models' => [
            'tenant' => Tenant::class,
            'subscription' => Subscription::class,
            'subscription_item' => SubscriptionItem::class,
        ],

        'webhook_path' => env('BILLING_WEBHOOK_PATH', 'billing/webhook'),

        // Used by PlanOrDefaultTrialResolver when a plan does not declare
        // its own trial_days.
        'trial_days' => 14,

        // Swap any of these for your own implementation. Each row is bound
        // as-is by BillingServiceProvider::register(); a host wanting a
        // closure binds the contract in its own provider instead.
        'implementations' => [
            CheckoutGateway::class => InlineCheckoutGateway::class,
            PaymentPlanRepository::class => EloquentPaymentPlanRepository::class,
            SubscriptionRepository::class => EloquentSubscriptionRepository::class,
            BillableResolver::class => TenantOrUserBillableResolver::class,
            PlanPolicy::class => SeatLimitPlanPolicy::class,
            TrialResolver::class => PlanOrDefaultTrialResolver::class,
            UnpaidTenantQuota::class => DefaultUnpaidTenantQuota::class,
            CheckoutRegionResolver::class => NullCheckoutRegionResolver::class,
        ],

        // Provisioning happens before settlement, so this caps how many
        // concurrently-unpaid tenants a single user can own before
        // StartSubscriptionCheckout refuses to start another.
        'unpaid_tenant_cap' => (int) env('BILLING_UNPAID_TENANT_CAP', 2),

        // Disable if your application syncs tenants to Stripe itself and
        // does not want SyncTenantToStripe firing a second write.
        'sync' => [
            'stripe_customer' => true,
        ],

        // Display order only — Stripe still decides which methods are
        // eligible; this only reorders what it was already going to show.
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

        // How a request is matched to a tenant. Changing this after tenants
        // already exist under a different mode does not migrate their
        // identification — a deploy-time choice, not a runtime toggle.
        'identification' => [
            'mode' => env('NUMEROSIS_TENANCY_IDENTIFICATION_MODE', IdentificationMode::Subdomain->value),
        ],

        // The stancl/tenancy central database connection name.
        'central_connection' => 'central',

        // The seeder new tenant databases run.
        'seeder' => TenantDatabaseSeeder::class,

        // An owner-closed tenant keeps its data for 'grace_days', during
        // which a reopen restores it. 'purge_closed' is what lets
        // tenancy:prune-orphaned-databases delete one past that window; keep
        // it off until you have backups you could restore from.
        'closure' => [
            'grace_days' => (int) env('NUMEROSIS_CLOSURE_GRACE_DAYS', 30),

            'purge_closed' => (bool) env('NUMEROSIS_PURGE_CLOSED_TENANTS', false),
        ],

        'provisioning' => [
            // Run in order, each its own link in a queued chain and each
            // recorded on the provision row, so a retry resumes. Insert your
            // own anywhere; there is no privileged first or last step.
            'steps' => [
                CreateTenantAction::class,
                CreateTenantDatabase::class,
                MigrateTenantDatabase::class,
                SeedTenantDatabase::class,
                AddTenantOwner::class,
                PromoteFirstUserToAdmin::class,
                LinkTenantSubscription::class,
                FinalizeTenantProvisioning::class,
            ],
        ],

        // 'registration' => ['steps' => [...]] is absent deliberately: the
        // shipped wizard steps fill it from their own register() when nothing
        // has, so core never names a class that might not be installed.

        // Caches the domain lookup every tenant request would otherwise pay
        // against the central database. Null follows whether
        // cache.serializable_classes can hold a cached tenant model.
        'cache_resolved_tenants' => null,

        // Swap any of these for your own implementation; registration and
        // password reset are Fortify's seams instead. The last three are auth,
        // not tenancy, and live here because TenancyServiceProvider binds it.
        'implementations' => [
            TenantDomainPolicy::class => DefaultTenantDomainPolicy::class,
            ProvisionsTenant::class => ProvisionTenant::class,
            TenantDatabaseManager::class => StanclTenantDatabaseManager::class,
            NotifiesTenantOwner::class => NotifiesTenantOwnerDirectly::class,
            ResolvesLoginCandidate::class => ResolveLoginCandidate::class,
            AuthenticatesLoginCandidate::class => AuthenticateLoginCandidate::class,
            SendsEmailVerificationNotification::class => SendEmailVerificationNotification::class,
        ],
    ],

];

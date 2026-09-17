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
use Nvade\Numerosis\Contracts\Auth\ExportsPersonalData;
use Nvade\Numerosis\Contracts\Auth\ResolvesLoginCandidate;
use Nvade\Numerosis\Contracts\Auth\SendsEmailVerificationNotification;
use Nvade\Numerosis\Contracts\Auth\SessionRegistry;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Contracts\Billing\CheckoutRegionResolver;
use Nvade\Numerosis\Contracts\Billing\Entitlements;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Contracts\Billing\PlanPolicy;
use Nvade\Numerosis\Contracts\Billing\SubscriptionRepository;
use Nvade\Numerosis\Contracts\Billing\TrialResolver;
use Nvade\Numerosis\Contracts\Billing\UnpaidTenantQuota;
use Nvade\Numerosis\Contracts\Billing\UsageCounter;
use Nvade\Numerosis\Contracts\Notifications\NotificationChannels;
use Nvade\Numerosis\Contracts\Notifications\NotifiesTenantOwner;
use Nvade\Numerosis\Contracts\Notifications\OperatorRecipient;
use Nvade\Numerosis\Contracts\Tenancy\DnsResolver;
use Nvade\Numerosis\Contracts\Tenancy\EncryptsArtifacts;
use Nvade\Numerosis\Contracts\Tenancy\ExportsTenantData;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseManager;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Database\Seeders\TenantDatabaseSeeder;
use Nvade\Numerosis\Enums\Tenancy\DatabaseDriver;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Features\Audit\ActivityLogFeature;
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
use Nvade\Numerosis\Models\Central\TenantMigrationRun;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Services\Auth\DatabaseSessionRegistry;
use Nvade\Numerosis\Services\Auth\PersonalDataExporter;
use Nvade\Numerosis\Services\Billing\DatabaseUsageCounter;
use Nvade\Numerosis\Services\Billing\DefaultUnpaidTenantQuota;
use Nvade\Numerosis\Services\Billing\EloquentPaymentPlanRepository;
use Nvade\Numerosis\Services\Billing\EloquentSubscriptionRepository;
use Nvade\Numerosis\Services\Billing\InlineCheckoutGateway;
use Nvade\Numerosis\Services\Billing\NullCheckoutRegionResolver;
use Nvade\Numerosis\Services\Billing\PlanEntitlements;
use Nvade\Numerosis\Services\Billing\PlanOrDefaultTrialResolver;
use Nvade\Numerosis\Services\Billing\SeatLimitPlanPolicy;
use Nvade\Numerosis\Services\Billing\TenantOrUserBillableResolver;
use Nvade\Numerosis\Services\Notifications\MailsConfiguredOperator;
use Nvade\Numerosis\Services\Notifications\NotifiesTenantOwnerDirectly;
use Nvade\Numerosis\Services\Notifications\PreferredNotificationChannels;
use Nvade\Numerosis\Services\Tenancy\ArtifactCipher;
use Nvade\Numerosis\Services\Tenancy\DefaultTenantDomainPolicy;
use Nvade\Numerosis\Services\Tenancy\PortableTenantDatabaseDumper;
use Nvade\Numerosis\Services\Tenancy\SqliteFileTenantDatabaseDumper;
use Nvade\Numerosis\Services\Tenancy\StanclTenantDatabaseManager;
use Nvade\Numerosis\Services\Tenancy\SystemDnsResolver;
use Nvade\Numerosis\Services\Tenancy\TenantDataExporter;

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

        // The screens that read the activity log: 'team/activity' for a
        // tenant's owner and admins, and the staff feed. Entries are written
        // either way.
        ActivityLogFeature::class,

        // Passwordless email OTP login, layered on Fortify rather than
        // replacing it. Off by default.
        // \Nvade\Numerosis\Features\Auth\OneTimePasswordFeature::class,

        // Staff screens on the central domain, under
        // 'routes.staff_prefix'. Off by default.
        // \Nvade\Numerosis\Features\Admin\StaffPanelFeature::class,

        // Support staff signing in as a tenant user, audited and banner-visible
        // for the whole session. Off by default.
        // \Nvade\Numerosis\Features\Admin\ImpersonationFeature::class,

        // Unauthenticated health document at 'routes.health_path', for uptime
        // monitors. Counts and booleans only. Off by default.
        // \Nvade\Numerosis\Features\Observability\HealthEndpointFeature::class,

        // The current-period usage screen, for plans that declare
        // `metadata.options.meters`. Off by default; reporting usage to Stripe
        // is numerosis.schedule.report_usage and is separate.
        // \Nvade\Numerosis\Features\Billing\UsageMeteringFeature::class,
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

        // Sends tenant usage counters to Stripe as meter events. Off by
        // default: a plan with no `metadata.options.meters` reports nothing,
        // and turning it on without meters configured only burns a cron slot.
        'report_usage' => (bool) env('SCHEDULE_REPORT_USAGE', false),

        // Compares those counters against Stripe's own summaries and alerts on
        // a gap. Worth having on wherever report_usage is.
        'reconcile_usage' => (bool) env('SCHEDULE_RECONCILE_USAGE', false),

        // Re-checks custom-domain DNS: claims waiting to verify, and serving
        // domains whose records may have been removed. Only meaningful under
        // IdentificationMode::CustomDomain.
        'verify_domains' => (bool) env('SCHEDULE_VERIFY_DOMAINS', false),

        // Closes impersonation rows nobody returned to. The per-request guard
        // covers the rest.
        'end_stale_impersonations' => (bool) env('SCHEDULE_END_STALE_IMPERSONATIONS', true),
        'prune_invitations' => (bool) env('SCHEDULE_PRUNE_INVITATIONS', true),

        // Runs numerosis:prune-data-exports, deleting subject access request
        // artefacts older than numerosis.privacy.keep_days.
        'prune_data_exports' => (bool) env('SCHEDULE_PRUNE_DATA_EXPORTS', true),

        // Runs numerosis:prune-tenant-backups, deleting artefacts older than
        // numerosis.tenancy.backup.keep_days.
        'prune_tenant_backups' => (bool) env('SCHEDULE_PRUNE_TENANT_BACKUPS', true),

        // Runs numerosis:prune-notifications, deleting read in-app
        // notifications older than numerosis.notifications.keep_days. Unread
        // ones are never pruned: nobody has looked at them yet.
        'prune_notifications' => (bool) env('SCHEDULE_PRUNE_NOTIFICATIONS', true),

        // Runs numerosis:prune-activity-log, deleting central entries older
        // than activitylog.clean_after_days (365 by default). Retention of an
        // audit log is a compliance decision, so the window is the host's.
        'prune_activity_log' => (bool) env('SCHEDULE_PRUNE_ACTIVITY_LOG', true),

        // Stamps a cache key every minute. The health document reports how
        // long ago, which is the only way to tell a stopped cron from a quiet
        // one.
        'heartbeat' => (bool) env('SCHEDULE_HEARTBEAT', true),
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

        // Where HealthEndpointFeature answers. Not '/up': that path is the
        // framework's own health slot and belongs to the host.
        'health_path' => env('NUMEROSIS_HEALTH_PATH', 'up/numerosis'),
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

        // Adds uncompromised() to Password::defaults(), which calls the Have I
        // Been Pwned range API. Turn off for an air-gapped deployment, or when
        // the host sets Password::defaults() itself.
        'check_compromised_passwords' => env('NUMEROSIS_CHECK_COMPROMISED_PASSWORDS', true),
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
    | Privacy
    |--------------------------------------------------------------------------
    |
    | Subject access requests and erasure. 'terms_version' is what a consent
    | row records; change it when your terms change and the next registration
    | records the new one. Retention windows are the host's decision — counsel
    | decides the numbers, this block is where their answer goes.
    */

    'privacy' => [
        // Where export artefacts land; falls back to the backup disk.
        'disk' => env('NUMEROSIS_PRIVACY_DISK'),

        // How long a download link stays valid. It is signed and single-use;
        // this is the outer bound on a link sitting in a mailbox.
        'link_expiry_minutes' => (int) env('NUMEROSIS_EXPORT_LINK_MINUTES', 60),

        // An export crosses every tenant the subject belongs to, so it is
        // expensive and an obvious abuse vector.
        'request_interval_hours' => (int) env('NUMEROSIS_EXPORT_INTERVAL_HOURS', 24),

        // Days an artefact is kept before numerosis:prune-data-exports
        // deletes it. The request row survives it as the audit trail.
        'keep_days' => (int) env('NUMEROSIS_EXPORT_KEEP_DAYS', 7),

        'terms_version' => env('NUMEROSIS_TERMS_VERSION', '1.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Headers
    |--------------------------------------------------------------------------
    |
    | Set on every HTML response by Http\Middleware\SecurityHeaders, which the
    | package appends to the 'web' group. A null or empty value omits that
    | header. 'except' holds path patterns for routes that share the group but
    | serve no browser: Stripe's webhook answers with text/html.
    |
    | The CSP ships report-only with the Stripe, Turnstile and Bunny Fonts
    | origins allowed. Watch your browser console for a week, add the origins
    | your own assets need, then set 'report_only' to false.
    */

    'security' => [
        'headers' => [
            'enabled' => env('NUMEROSIS_SECURITY_HEADERS', true),

            'except' => ['stripe/*', 'billing/webhook', 'telescope/*'],

            // includeSubDomains is load-bearing in subdomain identification
            // mode: without it every tenant host is exempt.
            'strict_transport_security' => 'max-age=31536000; includeSubDomains',

            'x_content_type_options' => 'nosniff',

            'referrer_policy' => 'strict-origin-when-cross-origin',

            'x_frame_options' => 'DENY',

            'permissions_policy' => 'camera=(), microphone=(), geolocation=()',

            'content_security_policy' => [
                'enabled' => true,

                'report_only' => env('NUMEROSIS_CSP_REPORT_ONLY', true),

                'report_uri' => env('NUMEROSIS_CSP_REPORT_URI'),

                'directives' => [
                    'default-src' => ["'self'"],

                    // Livewire injects an inline script and Alpine evaluates
                    // expressions, so neither allowance can be dropped.
                    'script-src' => [
                        "'self'",
                        "'unsafe-inline'",
                        "'unsafe-eval'",
                        'https://js.stripe.com',
                        'https://challenges.cloudflare.com',
                    ],

                    'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'],

                    'img-src' => ["'self'", 'data:', 'https:'],

                    'font-src' => ["'self'", 'data:', 'https://fonts.bunny.net'],

                    'connect-src' => ["'self'", 'https://api.stripe.com'],

                    'frame-src' => [
                        'https://js.stripe.com',
                        'https://hooks.stripe.com',
                        'https://challenges.cloudflare.com',
                    ],

                    'frame-ancestors' => ["'none'"],

                    'base-uri' => ["'self'"],

                    'form-action' => ["'self'"],
                ],
            ],
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
            'health_report' => 5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator notifications
    |--------------------------------------------------------------------------
    |
    | Where this installation's operators are told about a failure nobody else
    | sees. 'operator' is a mail address; null sends nothing at all. A host
    | wanting Slack or PagerDuty binds Contracts\Notifications\OperatorRecipient
    | to its own implementation instead of setting this.
    |
    | 'throttle_minutes' is how long one alert suppresses the next, so a bad
    | deploy failing two hundred provisions sends one mail.
    */

    'notifications' => [
        'operator' => env('NUMEROSIS_OPERATOR_EMAIL'),

        // How long a read in-app notification is kept before
        // numerosis:prune-notifications deletes it. Every user, every event,
        // forever is not a retention policy.
        'keep_days' => (int) env('NUMEROSIS_NOTIFICATIONS_KEEP_DAYS', 90),

        'throttle_minutes' => (int) env('NUMEROSIS_OPERATOR_THROTTLE_MINUTES', 15),
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
        TenantMigrationRun::class => null,
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
            UsageCounter::class => DatabaseUsageCounter::class,
            Entitlements::class => PlanEntitlements::class,
        ],

        // What a tenant gets with no active subscription, which is the normal
        // state during dunning and before the first checkout. Capabilities are
        // `features.slug` values; limits are read by
        // Contracts\Billing\Entitlements alongside a plan's own
        // `metadata.options.limits`, and an absent limit means uncapped.
        //
        // Empty by default, which is the behaviour that predates entitlements:
        // put 'seats' => 1 here to cap a workspace nobody is paying for.
        'free_tier' => [
            'capabilities' => [],

            'limits' => [],
        ],

        // Discounts. Coupons and promotion codes are Stripe objects; this
        // package applies, validates, displays and records them, and computes
        // no discounted amount itself.
        'promotions' => [

            // A promotion code offered to an owner on the close-workspace
            // screen, before the closure is confirmed. Null means no offer is
            // shown — and a code Stripe refuses is not shown either, so a
            // retired coupon degrades to the plain close flow.
            'retention_code' => env('BILLING_RETENTION_CODE'),
        ],

        // Usage-based billing. Which meters exist is plan data, not config:
        // each plan's `metadata.options.meters` names the counter key, the
        // Stripe event name, the meter id and the included allowance.
        'metering' => [

            // Units of difference billing:reconcile-usage accepts before it
            // alerts. Zero is the honest default — a meter that disagrees at
            // all disagrees about money.
            'tolerance' => (int) env('BILLING_METERING_TOLERANCE', 0),
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

    /*
    |--------------------------------------------------------------------------
    | Public API
    |--------------------------------------------------------------------------
    |
    | Read-only endpoints under the api prefix, authenticated by Sanctum tokens
    | issued per tenant user. Abilities are `context.action` pairs over
    | Enums\Auth\PermissionContext and PermissionAction — never a second
    | vocabulary.
    */

    'api' => [

        // Requests per minute per token, not per address: two tenants behind
        // one NAT must not spend each other's budget.
        'rate_limit' => (int) env('NUMEROSIS_API_RATE_LIMIT', 60),

        // How long a newly issued token lasts, in days. Null never expires,
        // which is a choice rather than a default worth inheriting silently.
        'token_expiry_days' => env('NUMEROSIS_API_TOKEN_EXPIRY_DAYS') === null
            ? null
            : (int) env('NUMEROSIS_API_TOKEN_EXPIRY_DAYS'),
    ],

    'tenancy' => [

        // How a request is matched to a tenant. Changing this after tenants
        // already exist under a different mode does not migrate their
        // identification — a deploy-time choice, not a runtime toggle.
        'identification' => [
            'mode' => env('NUMEROSIS_TENANCY_IDENTIFICATION_MODE', IdentificationMode::Subdomain->value),
        ],

        // Ownership proof for IdentificationMode::CustomDomain. A tenant
        // claiming a hostname proves it controls the zone with a TXT record,
        // and points traffic here with a CNAME (or an A record).
        'custom_domains' => [

            // The TXT record's host is '<prefix>.<domain>'.
            'challenge_prefix' => env('NUMEROSIS_DOMAIN_CHALLENGE_PREFIX', '_numerosis-challenge'),

            // What a customer's CNAME must point at. Defaults to the central
            // hostname, which is where traffic has to arrive.
            'cname_target' => env('NUMEROSIS_DOMAIN_CNAME_TARGET'),

            // A records accepted as an alternative to the CNAME, for zones
            // whose apex cannot carry one.
            'a_records' => array_values(array_filter(
                explode(',', (string) env('NUMEROSIS_DOMAIN_A_RECORDS', '')),
                static fn (string $address): bool => trim($address) !== '',
            )),

            // How long a claim keeps being retried before it is marked failed.
            // DNS propagates on its own timetable, so a check that fails is
            // 'not yet' rather than 'no'.
            'verification_window_hours' => (int) env('NUMEROSIS_DOMAIN_VERIFICATION_WINDOW', 72),

            // How long between automatic checks of one domain.
            'recheck_minutes' => (int) env('NUMEROSIS_DOMAIN_RECHECK_MINUTES', 60),

            // TLS presenters for the proxy in front of this deployment. The
            // package issues no certificates; it publishes the verified set.
            // See docs/host-requirements.md for the deployment matrix.
            'tls' => [
                'ask' => (bool) env('NUMEROSIS_TLS_ASK_ENDPOINT', false),
                'ask_path' => env('NUMEROSIS_TLS_ASK_PATH', 'numerosis/tls/ask'),
                'routers' => (bool) env('NUMEROSIS_TLS_ROUTERS_ENDPOINT', false),
                'routers_path' => env('NUMEROSIS_TLS_ROUTERS_PATH', 'numerosis/tls/routers'),

                // Caddy asks once per new SNI, so an uncached query here is a
                // denial-of-service vector.
                'cache_seconds' => (int) env('NUMEROSIS_TLS_CACHE_SECONDS', 60),

                // What the Traefik router config points traffic at.
                'service' => env('NUMEROSIS_TLS_TRAEFIK_SERVICE', 'numerosis'),
                'cert_resolver' => env('NUMEROSIS_TLS_CERT_RESOLVER', 'letsencrypt'),
            ],
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

        // Per-tenant snapshots: tenancy:backup / tenancy:restore, and the
        // final backup a purge takes before dropping a database. The default
        // dumper reads and writes through PDO and needs no binary; point a
        // driver at a binary dumper once a tenant database is too large for
        // that, and numerosis:install --verify-only will tell you when the
        // binary is missing.
        'backup' => [
            'disk' => env('NUMEROSIS_BACKUP_DISK', 'local'),

            'path' => env('NUMEROSIS_BACKUP_PATH', 'tenant-backups'),

            // Artefacts hold everything a tenant has. Encrypted with the
            // app key, streamed a block at a time, so a leaked bucket is not
            // a leaked customer database. Turning it off makes an artefact
            // readable by anything that can read the disk.
            'encrypt' => (bool) env('NUMEROSIS_BACKUP_ENCRYPT', true),

            // How long numerosis:prune-tenant-backups keeps an artefact.
            // Retention of a customer's whole database is a compliance
            // decision, so the window is yours.
            'keep_days' => (int) env('NUMEROSIS_BACKUP_KEEP_DAYS', 30),

            // A purge takes one last snapshot and refuses to drop the
            // database if it fails, which is what makes the closure recovery
            // window mean something.
            'before_purge' => (bool) env('NUMEROSIS_BACKUP_BEFORE_PURGE', true),

            // Seconds a binary dumper may run before it is killed.
            'timeout' => (int) env('NUMEROSIS_BACKUP_TIMEOUT', 900),

            'dumpers' => [
                DatabaseDriver::Mysql->value => PortableTenantDatabaseDumper::class,
                DatabaseDriver::Mariadb->value => PortableTenantDatabaseDumper::class,
                DatabaseDriver::Pgsql->value => PortableTenantDatabaseDumper::class,
                DatabaseDriver::Sqlite->value => SqliteFileTenantDatabaseDumper::class,
            ],
        ],

        // Days a member has to enrol after an owner turns the tenant's
        // two-factor requirement on. Zero locks unenrolled members out of the
        // tenant the moment the switch is flipped.
        'two_factor' => [
            'grace_days' => (int) env('NUMEROSIS_TWO_FACTOR_GRACE_DAYS', 7),
        ],

        // 'token_seconds' is how long a minted link may be redeemed for, and
        // 'session_minutes' how long the impersonated session runs before the
        // next request ends it. Both only matter under ImpersonationFeature.
        'impersonation' => [
            'token_seconds' => (int) env('NUMEROSIS_IMPERSONATION_TOKEN_SECONDS', 60),

            'session_minutes' => (int) env('NUMEROSIS_IMPERSONATION_SESSION_MINUTES', 60),

            // Outbound mail and notifications are dropped while impersonating,
            // so support cannot send confusing email from inside an account.
            'suppress_mail' => (bool) env('NUMEROSIS_IMPERSONATION_SUPPRESS_MAIL', true),
        ],

        // Fleet rollouts: `tenancy:migrate`. The queue is deliberately not
        // 'provisioning' — health reports that queue's depth as customers
        // waiting on a signup, and a fleet rollout would drown the signal.
        'migrations' => [
            'queue' => env('NUMEROSIS_MIGRATION_QUEUE', 'migrations'),

            // Tenants per chunk, and seconds to wait between chunks. Thousands
            // of ALTERs saturate a database server; the pause is the knob.
            'chunk' => (int) env('NUMEROSIS_MIGRATION_CHUNK', 50),

            'delay' => (int) env('NUMEROSIS_MIGRATION_DELAY', 0),
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
            DnsResolver::class => SystemDnsResolver::class,
            ProvisionsTenant::class => ProvisionTenant::class,
            TenantDatabaseManager::class => StanclTenantDatabaseManager::class,
            NotifiesTenantOwner::class => NotifiesTenantOwnerDirectly::class,
            NotificationChannels::class => PreferredNotificationChannels::class,
            OperatorRecipient::class => MailsConfiguredOperator::class,
            ResolvesLoginCandidate::class => ResolveLoginCandidate::class,
            AuthenticatesLoginCandidate::class => AuthenticateLoginCandidate::class,
            SendsEmailVerificationNotification::class => SendEmailVerificationNotification::class,
            SessionRegistry::class => DatabaseSessionRegistry::class,
            ExportsTenantData::class => TenantDataExporter::class,
            ExportsPersonalData::class => PersonalDataExporter::class,
            EncryptsArtifacts::class => ArtifactCipher::class,
        ],
    ],

];

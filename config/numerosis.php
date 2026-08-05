<?php

declare(strict_types=1);

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
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Invitation;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;

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
    | Booleans, not feature classes — routes/console.php is a scheduling
    | surface, not an app feature. "Does this deployment's cron run this
    | command" is an operations question, not a product one. telescope:prune
    | is gated separately, by class_exists (see routes/console.php) — not a
    | key here, since it has exactly one owner already.
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
    */

    'domains' => [
        // '{tenant}.'.env('DOMAIN'), not APP_URL's host — APP_URL carries the
        // central subdomain too (e.g. saasm.nvade.dev), which would put every
        // tenant one level too deep. Matches config('tenancy.tenant_domain').
        'tenant_pattern' => env('NUMEROSIS_TENANT_DOMAIN', '{tenant}.'.env('DOMAIN')),
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

];

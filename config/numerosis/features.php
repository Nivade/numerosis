<?php

declare(strict_types=1);

use Nvade\Numerosis\Features\Auth\EmailVerificationFeature;
use Nvade\Numerosis\Features\Auth\PasswordResetFeature;
use Nvade\Numerosis\Features\Billing\BillingNotificationsFeature;
use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Features\Modules\ModuleSystemFeature;
use Nvade\Numerosis\Features\Tenancy\ImpersonationFeature;
use Nvade\Numerosis\Features\Tenancy\MembershipsFeature;
use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Nvade\Numerosis\Models\Central\Tenant;

return [

    /*
    |--------------------------------------------------------------------------
    | Feature toggles
    |--------------------------------------------------------------------------
    */

    'features' => [

        // Requires TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY — see .env.example.
        TurnstileFeature::class,

        // Some features are NOT listed here, because they ship in satellite
        // packages whose providers register them through Features::register():
        // OAuth login (nvade/numerosis-auth-ui), and the two panel toggles
        // plus the audit-log UI (nvade/numerosis-filament). Naming a
        // satellite's class in core's config would make core boot against a
        // class that may not be installed.

        // The per-tenant module system, storefront included. Comment out to
        // run no modules at all.
        ModuleSystemFeature::class,

        // Team invitations. The invite/accept flow, InvitationResource, and
        // the invitation-sent notification.
        InvitationsFeature::class,

        // Self-serve tenant registration wizard (/get-started). Tenant
        // provisioning itself is unaffected — see the class docblock.

        // Not a real toggle — see the class docblock. Registered
        // unconditionally; do not comment out.
        EmailVerificationFeature::class,

        // Payment confirmed / payment failed / tenant suspended emails.
        BillingNotificationsFeature::class,

        // Password reset (login itself is passwordless). Does not cover
        // password.confirm — see the class docblock.
        PasswordResetFeature::class,

        // Marketing pages (terms/privacy/about/features) are the *product's*,
        // not the framework's, and live in the host app. Core keeps only the
        // `home` route, which it must always register.

        // The account UI (settings, workspace list, billing portal) ships in
        // nvade/numerosis-account, which registers its own feature — the class
        // is deliberately not named here, since core must not boot against a
        // class that may not be installed. Core reads the name it needs
        // through Support\Ui\AccountPages::FEATURE.

        // Tenant membership UI (Team cluster / Users resource). Does not
        // gate InvitationsFeature — see the class docblock.
        MembershipsFeature::class,

        // "Impersonate owner" on the central Tenants table — opens a real
        // session as the tenant's owner, for support. Security-sensitive:
        // remove this if central-panel staff should not be able to do that.
        ImpersonationFeature::class,

    ],

];

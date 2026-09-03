<?php

declare(strict_types=1);

use Nvade\Numerosis\Features\Auth\EmailVerificationFeature;
use Nvade\Numerosis\Features\Auth\PasswordResetFeature;
use Nvade\Numerosis\Features\Auth\SocialLoginFeature;
use Nvade\Numerosis\Features\Billing\BillingNotificationsFeature;
use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Features\Tenancy\MembershipsFeature;
use Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature;
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

        // The account UI (settings, workspace list, billing portal) is
        // unconditional core routing now — nvade/numerosis-account folded
        // into core in Phase 3 of `.claude/plans/humming-nibbling-flame.md`
        // with no feature flag of its own, so there is nothing to list here.

        // OAuth login: provider buttons, connected-accounts, the callback
        // route. Enabling a provider needs credentials in config/services.php
        // plus an entry in numerosis.social.providers — see the class
        // docblock.
        SocialLoginFeature::class,

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

        // Marketing pages (terms/privacy/about/features) are the *product's*,
        // not the framework's, and live in the host app. Core keeps only the
        // `home` route, which it must always register.

        // Tenant membership UI (Team cluster / Users resource). Does not
        // gate InvitationsFeature — see the class docblock.
        MembershipsFeature::class,

    ],

];

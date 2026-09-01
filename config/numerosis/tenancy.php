<?php

declare(strict_types=1);

use Nvade\Numerosis\Actions\Auth\AuthenticateLoginCandidate;
use Nvade\Numerosis\Actions\Auth\CreateRegisteredUser;
use Nvade\Numerosis\Actions\Auth\ResolveLoginCandidate;
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
use Nvade\Numerosis\Contracts\Invitations\CreatesInvitedUser;
use Nvade\Numerosis\Contracts\Invitations\InvitationRepository;
use Nvade\Numerosis\Contracts\Modules\ModuleRegistry;
use Nvade\Numerosis\Contracts\Notifications\NotifiesTenantOwner;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseManager;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Services\Auth\EloquentSocialAccountRepository;
use Nvade\Numerosis\Services\Invitations\EloquentInvitationRepository;
use Nvade\Numerosis\Services\Modules\EloquentModuleRegistry;
use Nvade\Numerosis\Services\Notifications\NotifiesTenantOwnerDirectly;
use Nvade\Numerosis\Services\Tenancy\DefaultTenantDomainPolicy;
use Nvade\Numerosis\Services\Tenancy\StanclTenantDatabaseManager;

return [

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    */

    'tenancy' => [

        /*
        |----------------------------------------------------------------------
        | Tenant Identification Mode
        |----------------------------------------------------------------------
        |
        | How a request is matched to a tenant. One of
        | Nvade\Numerosis\Enums\Tenancy\IdentificationMode's cases:
        |
        | - 'subdomain'     (default) — "{tenant}.".domains.apex, e.g.
        |                    acme.example.com. Unchanged, original behaviour.
        | - 'custom_domain' — each tenant supplies their own fully-qualified
        |                     domain (e.g. app.acme.com) at registration; the
        |                     tenant's safe id/slug stays separate from it.
        | - 'path'          — "{central_domain}/{tenant}/...". No DNS or
        |                     `domains` rows involved at all.
        |
        | Changing this after tenants already exist under a different mode
        | does not migrate their identification — treat it as a deploy-time
        | choice, not a runtime toggle.
        |
        */

        'identification' => [
            'mode' => env('NUMEROSIS_TENANCY_IDENTIFICATION_MODE', Nvade\Numerosis\Enums\Tenancy\IdentificationMode::Subdomain->value),
        ],

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
        | Registration Wizard Steps
        |----------------------------------------------------------------------
        |
        | Read by Registration::steps() — the ordered list of step
        | components the self-serve signup wizard walks through. Nested
        | under 'tenancy' to mirror 'provisioning.steps' above deliberately,
        | not under a top-level 'registration' key.
        |
        | A step this package does not ship its own view for is not
        | auto-registered by RegistrationWizardFeature — register your own
        | Livewire component for it first (see that class's docblock).
        |
        | At least one step in this list must implement
        | Nvade\Numerosis\Contracts\Tenancy\ProvidesTenantIdentity, or
        | RegistrationWizardFeature refuses to boot — CreateTenant has no
        | source for the tenant's identifier/display name otherwise.
        |
        */

        // 'registration' => ['steps' => [...]] is intentionally absent.
        // The four shipped steps belong to nvade/numerosis-onboarding, which
        // fills this key from its own register() when nothing has set it.
        // Core cannot carry the default: naming those classes here would put
        // a package core does not depend on into core's own config file, and
        // a host that declines the wizard would be reading a list of classes
        // that do not exist. Override it in a published config to reorder or
        // replace the steps; a value present here wins.

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
            ResolvesLoginCandidate::class => ResolveLoginCandidate::class,
            AuthenticatesLoginCandidate::class => AuthenticateLoginCandidate::class,
            ResolvesPostLoginRedirectUrl::class => ResolvePostLoginRedirectUrl::class,
            CreatesRegisteredUser::class => CreateRegisteredUser::class,
            SendsEmailVerificationNotification::class => SendEmailVerificationNotification::class,
            CreatesInvitedUser::class => CreateInvitedUser::class,
        ],

    ],

];

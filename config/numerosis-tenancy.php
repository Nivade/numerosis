<?php

declare(strict_types=1);

use Nvade\Numerosis\Actions\Auth\AuthenticateLoginCandidate;
use Nvade\Numerosis\Actions\Auth\CreateRegisteredUser;
use Nvade\Numerosis\Actions\Auth\FindLoginCandidate;
use Nvade\Numerosis\Actions\Auth\ResolvePostLoginRedirectUrl;
use Nvade\Numerosis\Actions\Auth\SendEmailVerificationNotification;
use Nvade\Numerosis\Actions\Invitations\CreateInvitedUser;
use Nvade\Numerosis\Actions\Tenancy\AddTenantOwner;
use Nvade\Numerosis\Actions\Tenancy\CreateTenant;
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
use Nvade\Numerosis\Services\Tenancy\DefaultTenantDomainPolicy;
use Nvade\Numerosis\Support\Defaults\EloquentInvitationRepository;
use Nvade\Numerosis\Support\Defaults\EloquentModuleRegistry;
use Nvade\Numerosis\Support\Defaults\EloquentSocialAccountRepository;
use Nvade\Numerosis\Support\Defaults\NotifiesTenantOwnerDirectly;
use Nvade\Numerosis\Support\Defaults\StanclTenantDatabaseManager;

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant Provisioning Pipeline
    |--------------------------------------------------------------------------
    |
    | Steps run in order, as links in ProvisionTenant's queued chain on the
    | `provisioning` queue, every time a tenant is provisioned. The first
    | entry must create/find the tenant and return it — every step after
    | that (including any you append) is invoked as
    | ::run(Tenant $tenant, TenantProvisionData $data). LinkTenantSubscription
    | (only when a Stripe subscription id is present) and
    | FinalizeTenantProvisioning are appended automatically after these — they
    | cannot be reordered here, because FinalizeTenantProvisioning is the sole
    | emitter of the "provisioning finished" signal. Every step must be
    | idempotent: the whole list re-runs on retry.
    |
    | This is the one place business-level provisioning order is defined.
    | The separate physical-database segment (create/migrate/seed the tenant
    | database) lives in TenancyServiceProvider::$tenantCreatedJobs — kept
    | apart because those are raw queue jobs constructed `new $job($tenant)`,
    | not AsAction steps invoked `::run($tenant, $data)`, and because tests
    | override that list to swap in a fast template-clone stand-in. See
    | .claude/rules/tenant-provisioning.md.
    |
    */

    'provisioning' => [
        'steps' => [
            CreateTenant::class,
            AddTenantOwner::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Contract Bindings
    |--------------------------------------------------------------------------
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
        // components, so an unbound default leaves the package broken for
        // any consumer until they re-derive this list by hand.
        ResolvesLoginCandidate::class => FindLoginCandidate::class,
        AuthenticatesLoginCandidate::class => AuthenticateLoginCandidate::class,
        ResolvesPostLoginRedirectUrl::class => ResolvePostLoginRedirectUrl::class,
        CreatesRegisteredUser::class => CreateRegisteredUser::class,
        SendsEmailVerificationNotification::class => SendEmailVerificationNotification::class,
        CreatesInvitedUser::class => CreateInvitedUser::class,
    ],

];

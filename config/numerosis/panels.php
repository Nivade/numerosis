<?php

declare(strict_types=1);

use Nvade\Numerosis\Models\Central\Tenant;

return [

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

        // This whole section is core's, deliberately, even though the panels
        // themselves live in nvade/numerosis-filament: a satellite writing
        // three segments deep into another package's config namespace is the
        // Arr::set() auto-vivification hazard .claude/rules/
        // package-host-bootstrap.md records.
        //
        // Which of the two panels Filament treats as the application default
        // (the one a bare '/' resolves into). 'admin' | 'tenant' | null —
        // null registers neither as default, which is only safe if a host's
        // own panel provider supplies one, since Filament otherwise has no
        // panel to route an unscoped request to.
        //
        // 'provider' lets a host replace either panel provider
        // (Nvade\NumerosisFilament\Providers\NumerosisAdminPanelProvider /
        // NumerosisTenantPanelProvider) with its own class entirely. Core
        // registers whatever this names; numerosis-filament stands down for
        // that panel rather than registering a second provider for the same
        // panel id. Left null with numerosis-filament installed, its own
        // provider registers, gated the same way it always was:
        // AdminPanelFeature/TenantPanelFeature via each plugin's own
        // shouldRegisterPanel(). Left null without it, no panel registers and
        // filament/filament is not needed at all.
        // 'tenant.login' and 'admin.tenant_registration_component' are the two
        // seams that keep the Filament layer from hard-depending on the auth
        // and onboarding UI. Both are read defensively — a null value, or a
        // class that isn't installed, means "skip that wiring", never a fatal:
        //
        //   - 'tenant.login' is the Livewire component Filament serves as the
        //     tenant panel's login page. Null leaves Filament's own default in
        //     place. Note the failure mode this closes: `->login(Foo::class)`
        //     is a compile-time string, so a missing class registers fine and
        //     only fatals at the first /login hit on a tenant subdomain.
        //   - 'admin.tenant_registration_component' is the Livewire *alias*
        //     (not a class) the RegisterTenant page embeds. Deliberately an
        //     alias: the page belongs to the Filament layer and the wizard to
        //     the onboarding layer, so neither should name the other's class.
        //     Null hides the page and ListTenants' "New Tenant" action with it.
        //
        // Both defaults point at what this package itself ships today. When a
        // layer moves to its own package, the default moves with it — into
        // that package's own config file, not this one.
        'default' => 'admin',
        'admin' => [
            'provider' => null,
            'tenant_registration_component' => 'tenant-registration',
        ],
        'tenant' => [
            'provider' => null,
            // Filled by nvade/numerosis-auth-ui's provider when installed;
            // null leaves Filament's own login page in place.
            'login' => null,
        ],
    ],

];

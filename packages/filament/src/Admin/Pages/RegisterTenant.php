<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Config;
use Override;

class RegisterTenant extends Page
{
    protected string $view = 'numerosis::filament.admin.pages.register-tenant';

    /**
     * Was 'components.layouts.app.none', a view that doesn't exist —
     * throwing Livewire\Features\SupportPageComponents\MissingLayoutException
     * every time this page loaded. The wizard this page embeds
     * (Nvade\Numerosis\Livewire\Tenant\Registration\Registration) uses the
     * correct reference, 'layouts::app.none' — registered by
     * NumerosisServiceProvider against resources/views/layouts/app/none.blade.php.
     * Went unnoticed because this route had no traffic until ListTenants'
     * "New Tenant" action started linking here (see that class's docblock).
     */
    protected static string $layout = 'layouts::app.none';

    /**
     * The Livewire component alias this page embeds, or `null` when no
     * registration wizard is installed.
     *
     * An **alias**, not a class: this page belongs to the Filament layer and
     * the wizard to the onboarding layer, so neither may name the other's
     * class. The alias is also what the wizard is really addressed by —
     * `.claude/rules/tenant-registration-wizard.md` records what using the raw
     * FQCN instead cost the last time.
     */
    public static function wizardComponent(): ?string
    {
        $component = Config::get('numerosis.panels.admin.tenant_registration_component');

        return is_string($component) && $component !== '' ? $component : null;
    }

    /**
     * Filament discovers every page in this directory, so opting out has to
     * happen here rather than at the plugin's registration call. Without this
     * a host running the panel with no wizard installed gets a routable page
     * whose only content is an `@livewire()` call for a component Livewire
     * cannot find — `Unable to find component: [tenant-registration]`, thrown
     * at render, from a nav item that looked perfectly ordinary.
     */
    #[Override]
    public static function canAccess(): bool
    {
        return static::wizardComponent() !== null && parent::canAccess();
    }

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return static::wizardComponent() !== null && parent::shouldRegisterNavigation();
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Pages;

use Filament\Pages\Page;

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
}

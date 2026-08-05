<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Pages;

use Filament\Pages\Page;

class RegisterTenant extends Page
{
    protected string $view = 'numerosis::filament.admin.pages.register-tenant';

    protected static string $layout = 'components.layouts.app.none';
}

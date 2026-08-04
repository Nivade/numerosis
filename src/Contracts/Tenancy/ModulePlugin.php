<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Filament\Contracts\Plugin;

/**
 * A Filament plugin registered conditionally on a tenant module being
 * enabled (see Nvade\Numerosis\Concerns\InteractsWithTenantModules and
 * TenantAdminPanelProvider::enabledModulePlugins()). Filament's own Plugin
 * contract declares no static constructor, so this adds the one every
 * concrete plugin (e.g. Nvade\Chat\ChatPlugin) already defines by
 * convention — declaring it here is what lets the slug => plugin-class map
 * be called generically.
 */
interface ModulePlugin extends Plugin
{
    public static function make(): static;
}

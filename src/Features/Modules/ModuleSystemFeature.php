<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Modules;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * The per-tenant module system: module Filament plugins, the marketplace and
 * module resource, the `tenants:*-module` commands, and purchasing.
 *
 * Remove it from `numerosis.features` and no modules run at all. Purchased
 * module rows and their tenant tables are left intact — the system is
 * switched off, not uninstalled.
 */
class ModuleSystemFeature implements NamedFeature
{
    public const NAME = 'modules';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register: this feature is read at call time.
    }
}

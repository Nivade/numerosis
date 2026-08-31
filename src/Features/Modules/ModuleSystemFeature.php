<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Modules;

use InterNACHI\Modular\Support\Facades\Modules;
use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Support\Features;

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

    /**
     * Whether module code may run at all: the feature switch *and* the module
     * registry actually being installed.
     *
     * `internachi/modular` is `suggest`, so every path that reaches
     * `InterNACHI\Modular\*` asks this first rather than repeating the
     * `class_exists()` — the two questions ("the host turned it off" and "the
     * host never installed it") produce the same answer everywhere, and one
     * unguarded call site is a fatal rather than a disabled feature.
     *
     * The import above is safe: a bare `use` is lazy, and `Modules::class` on
     * an imported name is a string, not a class fetch. Only `class_exists()`
     * itself attempts the autoload.
     */
    public static function available(): bool
    {
        return Features::enabled(self::NAME) && class_exists(Modules::class);
    }

    public function bootstrap(): void
    {
        // Nothing to register: this feature is read at call time.
    }
}

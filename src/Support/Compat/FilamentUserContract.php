<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Compat;

/**
 * `implements`/`use trait` clauses resolve their target eagerly, at class
 * declaration time — unlike a method's parameter or return type, which
 * Laravel checks lazily. That eagerness is what forced `filament/filament`
 * onto every consumer via {@see \Nvade\Numerosis\Models\User}, regardless
 * of whether the admin panel is enabled. This interface always exists, so
 * the base model can always `implements` it; which behaviour it actually
 * carries depends on whether Filament is installed.
 *
 * See `DEPENDENCIES.md`'s "Known gap" note and `docs/host-requirements.md`
 * for the panel-registration half of this fix.
 */
if (interface_exists(\Filament\Models\Contracts\FilamentUser::class)) {
    interface FilamentUserContract extends \Filament\Models\Contracts\FilamentUser {}
} else {
    interface FilamentUserContract {}
}

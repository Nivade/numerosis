<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Compat;

/**
 * Conditional-definition shim: `use <Trait>` resolves its target eagerly, at
 * class-declaration time, so a base model composing a trait from an optional
 * package cannot even autoload without it. Making the *trait itself*
 * conditional is what keeps the package optional — see
 * `.ai/rules/optional-dependencies.md`. Here, for `spatie/laravel-activitylog`. Without that
 * package installed, {@see \Nvade\Numerosis\Models\Tenant\User} simply stops
 * logging activity; `getActivitylogOptions()` on it becomes dead code,
 * never called by anything that isn't the trait itself.
 */
if (trait_exists(\Spatie\Activitylog\Models\Concerns\LogsActivity::class)) {
    trait LogsActivityIfInstalled
    {
        use \Spatie\Activitylog\Models\Concerns\LogsActivity;
    }
} else {
    trait LogsActivityIfInstalled {}
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Compat;

/**
 * Conditional-definition shim for `spatie/laravel-activitylog`. Without that
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

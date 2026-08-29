<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Compat;

/**
 * See {@see FilamentUserContract} for why this indirection exists — same
 * eager-`use trait` problem, for `spatie/laravel-activitylog`. Without that
 * package installed, {@see \Nvade\Numerosis\Models\Tenant\User} and
 * {@see \Nvade\Numerosis\Models\Tenant\Invitation} simply stop logging
 * activity; `getActivitylogOptions()` on either model becomes dead code,
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

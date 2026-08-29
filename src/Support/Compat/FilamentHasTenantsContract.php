<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Compat;

/**
 * See {@see FilamentUserContract} for why this indirection exists — same
 * eager-`implements` problem, for Filament's own multi-tenancy contract
 * (distinct from {@see \Nvade\Numerosis\Contracts\Tenancy\HasTenants}).
 */
if (interface_exists(\Filament\Models\Contracts\HasTenants::class)) {
    interface FilamentHasTenantsContract extends \Filament\Models\Contracts\HasTenants {}
} else {
    interface FilamentHasTenantsContract {}
}

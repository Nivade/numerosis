<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns;

/**
 * Identifies a model by `global_id`, the column that recognises the same
 * person across the central and tenant databases.
 *
 * Compose it into any model that syncs between the two.
 *
 * On `stancl/tenancy:dev-master`, `Stancl\Tenancy\ResourceSyncing\ResourceSyncing`
 * (composed via `Support\Compat\Tenancy\ResourceSyncing`) declares both
 * methods itself — v3's did not, only called them. Every model that composes
 * both this trait and that one (`CentralUser`, `Tenant\User`) would hit a
 * fatal trait-method collision under dev-master if this trait redeclared
 * them too, so on that version it contributes nothing and the vendor trait's
 * identical `'global_id'` implementation wins instead. See
 * `.claude/rules/stancl-tenancy-v4.md`.
 */
if (class_exists(\Stancl\Tenancy\Enums\RouteMode::class)) {
    trait HasGlobalIdentity
    {
        //
    }
} else {
    trait HasGlobalIdentity
    {
        public function getGlobalIdentifierKeyName(): string
        {
            return 'global_id';
        }

        public function getGlobalIdentifierKey(): string
        {
            return $this->global_id;
        }
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Compat\Tenancy;

/**
 * See {@see Syncable} for the mechanism, applied to a concrete class rather
 * than an interface/trait — same reason `extends` needs the same
 * conditional-definition treatment as `implements`. v3:
 * `Stancl\Tenancy\Database\Models\TenantPivot` (hand-rolls its own `boot()`
 * sync-triggering). dev-master: `Stancl\Tenancy\ResourceSyncing\TenantPivot`
 * (`use TriggerSyncingEvents;` instead) — behaviourally equivalent, not a
 * straight rename.
 *
 * Declaration site: `src/Models/Central/Membership.php` (`extends`).
 */
if (class_exists(\Stancl\Tenancy\Enums\RouteMode::class)) {
    class TenantPivot extends \Stancl\Tenancy\ResourceSyncing\TenantPivot {}
} else {
    class TenantPivot extends \Stancl\Tenancy\Database\Models\TenantPivot {}
}

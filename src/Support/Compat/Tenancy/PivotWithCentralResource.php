<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Compat\Tenancy;

/**
 * Only exists on dev-master (`Stancl\Tenancy\ResourceSyncing\PivotWithCentralResource`);
 * v3 has no equivalent because v3's `TenantPivot` never validated attach
 * direction at all. Without it, dev-master's `TriggerSyncingEvents` throws
 * `CentralResourceNotAvailableInPivotException` the moment anything attaches
 * from the tenant side (`$tenant->users()->attach($user, ...)`) rather than
 * the central side (`$user->tenants()->attach($tenant, ...)`) — production
 * code (`AddTenantOwner`) already only ever attaches from the central side,
 * but the test suite's `$tenant->users()->attach(...)` convenience calls do
 * not, so `Membership` implements this to keep both directions working
 * rather than rewriting ~35 test call sites. See
 * `.claude/rules/stancl-tenancy-v4.md`.
 *
 * Declaration site: `src/Models/Central/Membership.php`.
 */
if (class_exists(\Stancl\Tenancy\Enums\RouteMode::class)) {
    interface PivotWithCentralResource extends \Stancl\Tenancy\ResourceSyncing\PivotWithCentralResource {}
} else {
    interface PivotWithCentralResource {}
}

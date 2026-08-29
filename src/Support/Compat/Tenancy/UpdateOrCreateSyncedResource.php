<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Compat\Tenancy;

/**
 * See {@see Syncable} for the mechanism. v3: `Stancl\Tenancy\Listeners\UpdateSyncedResource`.
 * dev-master: `Stancl\Tenancy\ResourceSyncing\Listeners\UpdateOrCreateSyncedResource`
 * (renamed, not just moved — `.claude/rules/stancl-tenancy-v4.md` records
 * that both still declare `handle(SyncedResourceSaved $event): void` and
 * `public static bool $shouldQueue = false`, so this shim is a plain
 * re-export with no adaptation needed).
 *
 * `src/Listeners/Tenancy/UpdateSyncedResource.php` extends this and does
 * not override `handle()` at all — see that file's docblock for why: an
 * override's parameter type is checked for LSP compatibility against
 * whichever branch PHPStan resolves this conditionally-declared class to,
 * which is not necessarily the branch that is actually installed.
 */
if (class_exists(\Stancl\Tenancy\Enums\RouteMode::class)) {
    class UpdateOrCreateSyncedResource extends \Stancl\Tenancy\ResourceSyncing\Listeners\UpdateOrCreateSyncedResource {}
} else {
    class UpdateOrCreateSyncedResource extends \Stancl\Tenancy\Listeners\UpdateSyncedResource {}
}

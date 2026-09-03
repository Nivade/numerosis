<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Nvade\Numerosis\Models\Permission;

/**
 * The shape a host takes to add non-CRUD verbs to one permission context.
 *
 * Core ships no such context — the module system's `purchase`/`cancel
 * modules` was the only one and went in Phase 2 of
 * `.claude/plans/humming-nibbling-flame.md` — so this fixture is the only
 * thing keeping {@see Permission::additionalActions()} exercised.
 */
class PermissionWithExtraActions extends Permission
{
    /**
     * @return array<string, list<string>>
     */
    public static function additionalActions(): array
    {
        return [
            'widgets' => ['publish', 'archive'],
        ];
    }
}

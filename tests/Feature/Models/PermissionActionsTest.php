<?php

declare(strict_types=1);

use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Tests\Support\PermissionWithExtraActions;

/*
 * `Permission::additionalActions()` returns `[]` in core and has exactly one
 * caller, so nothing else in the suite reaches it: the module system was its
 * only in-repo consumer and was deleted in Phase 2 of
 * `.claude/plans/humming-nibbling-flame.md`. Left uncovered the seam reads as
 * dead code and gets deleted by the next cleanup.
 *
 * The middle test is the one with teeth. It fails against `self::` in
 * `actionsFor()` — verified, not assumed — because `self` binds to
 * `Permission` at compile time and never reaches the subclass override.
 */

it('gives a core context exactly the default CRUD actions', function (): void {
    expect(Permission::additionalActions())->toBe([])
        ->and(Permission::actionsFor('users'))->toBe(Permission::defaultActions());
});

it('appends a subclass\'s additional actions after the defaults', function (): void {
    expect(PermissionWithExtraActions::actionsFor('widgets'))
        ->toBe([...Permission::defaultActions(), 'publish', 'archive']);
});

it('leaves a subclass\'s other contexts on the defaults', function (): void {
    expect(PermissionWithExtraActions::actionsFor('users'))
        ->toBe(Permission::defaultActions());
});

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Role;

class PromoteFirstCentralUserToAdmin
{
    use AsAction;

    // Mirrors Nvade\Numerosis\Actions\Tenancy\PromoteFirstUserToAdmin; no-ops if unseeded.
    public function handle(CentralUser $user): void
    {
        // Not CentralUser::query(): CentralUser is abstract (see
        // .claude/plans/package-extraction.md Phase 4.4), and late static
        // binding on a call written as `CentralUser::` resolves `new static`
        // to the abstract class itself. $user::query() resolves against the
        // host's concrete stub instead, since $user is always an instance of
        // it.
        if ($user::query()->whereKeyNot($user->getKey())->exists()) {
            return;
        }

        $role = Role::query()
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->first();

        if (! $role instanceof Role) {
            return;
        }

        $user->assignRole($role);
    }
}

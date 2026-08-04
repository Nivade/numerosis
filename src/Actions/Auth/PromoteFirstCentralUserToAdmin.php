<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Role;
use Lorisleiva\Actions\Concerns\AsAction;

class PromoteFirstCentralUserToAdmin
{
    use AsAction;

    // Mirrors Nvade\Numerosis\Actions\Tenancy\PromoteFirstUserToAdmin; no-ops if unseeded.
    public function handle(CentralUser $user): void
    {
        if (CentralUser::query()->whereKeyNot($user->getKey())->exists()) {
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

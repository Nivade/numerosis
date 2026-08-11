<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Role;

class PromoteFirstCentralUserToAdmin
{
    use AsAction;

    /**
     * Grants the admin role to the first central user, so a fresh install has
     * someone who can reach the admin panel. Does nothing once a second user
     * exists, or before roles are seeded.
     */
    public function handle(CentralUser $user): void
    {
        // Queried through the instance, so a subclass of your own is honoured.
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

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Auth\SystemRole;
use Nvade\Numerosis\Events\Auth\AdminGranted;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Role;

class PromoteFirstCentralUserToAdmin
{
    use AsAction;

    /**
     * Grants the admin role to the first central user, so a fresh install has
     * someone holding it. Does nothing once a second user exists, or before
     * roles are seeded.
     */
    public function handle(CentralUser $user): void
    {
        // Queried through the instance, so a subclass of your own is honoured.
        if ($user::query()->whereKeyNot($user->getKey())->exists()) {
            return;
        }

        $role = Role::query()
            ->where('name', SystemRole::Admin->value)
            ->where('guard_name', 'web')
            ->first();

        if (! $role instanceof Role) {
            return;
        }

        $user->assignRole($role);

        event(new AdminGranted($user->global_id, null));
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\CentralUser;

class DeleteUserAccount
{
    use AsAction;

    public function handle(CentralUser $user): bool
    {
        $isOwner = $user->tenants()
            ->wherePivot('role', 'owner')
            ->exists();

        if ($isOwner) {
            return false;
        }

        return (bool) $user->delete();
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Nvade\Numerosis\Models\Central\CentralUser;
use Lorisleiva\Actions\Concerns\AsAction;

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

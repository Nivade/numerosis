<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Nvade\Numerosis\Exceptions\Tenancy\NoPromotableUser;
use Nvade\Numerosis\Models\Tenant\User;
use Lorisleiva\Actions\Concerns\AsAction;

class PromoteFirstUserToAdmin
{
    use AsAction;

    public function handle(\Nvade\Numerosis\Models\Central\Tenant $tenant): void
    {
        $tenant->run(function () {

            $user = User::where('is_bot', false)->first();

            throw_unless($user, NoPromotableUser::class, 'No non-bot users found');

            $user->assignRole('admin');
        });
    }
}

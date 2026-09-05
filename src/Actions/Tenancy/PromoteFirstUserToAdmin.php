<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Events\Auth\AdminGranted;
use Nvade\Numerosis\Exceptions\Tenancy\NoPromotableUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User;
use Nvade\Numerosis\Support\Numerosis;

class PromoteFirstUserToAdmin
{
    use AsAction;

    public function handle(Tenant $tenant): void
    {
        $userClass = Numerosis::model(User::class);
        $tenantId = (string) $tenant->getTenantKey();

        $tenant->run(function () use ($userClass, $tenantId) {

            /** @var User|null $user */
            $user = $userClass::where('is_bot', false)->first();

            throw_unless($user, NoPromotableUser::class, 'No non-bot users found');

            $user->assignRole('admin');

            event(new AdminGranted($user->global_id, null, $tenantId));
        });
    }
}

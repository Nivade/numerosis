<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Invitations;

use Illuminate\Support\Facades\Hash;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Invitations\CreatesInvitedUser;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Tenant\Invitation;
use Nvade\Numerosis\Support\Numerosis;

/**
 * @method static CentralUser run(Invitation $invitation, string $name, string $password)
 */
class CreateInvitedUser implements CreatesInvitedUser
{
    use AsAction;

    public function handle(Invitation $invitation, string $name, string $password): CentralUser
    {
        return $this->create($invitation, $name, $password);
    }

    public function create(Invitation $invitation, string $name, string $password): CentralUser
    {
        $centralUserClass = Numerosis::model(CentralUser::class);

        return $centralUserClass::create([
            'name' => $name,
            'email' => $invitation->email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);
    }
}

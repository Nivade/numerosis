<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Support\Facades\Hash;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\User;

class UpdateUserPassword
{
    use AsAction;

    public function handle(User $user, string $password): void
    {
        $user->update([
            'password' => Hash::make($password),
        ]);
    }
}

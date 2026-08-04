<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Nvade\Numerosis\Models\User;
use Illuminate\Support\Facades\Hash;
use Lorisleiva\Actions\Concerns\AsAction;

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

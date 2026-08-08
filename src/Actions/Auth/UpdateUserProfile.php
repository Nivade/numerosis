<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\User;

class UpdateUserProfile
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, array $data): void
    {
        $user->fill($data);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Lorisleiva\Actions\Concerns\AsAction;

class ResendVerificationNotification
{
    use AsAction;

    public function handle(MustVerifyEmail $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->sendEmailVerificationNotification();
    }
}

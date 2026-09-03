<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Auth\ResetPasswordData;
use Nvade\Numerosis\Models\User;
use RuntimeException;

/**
 * Fortify's `ResetsUserPasswords` slot, bound via
 * `Fortify::resetUserPasswordsUsing()`. `NewPasswordController` calls this
 * from inside the password broker's callback, after the broker has already
 * verified the token — only the new password needs validating here.
 *
 * No core action covered this before Phase 4; the logic lived inside
 * `auth-ui`'s now-deleted `ResetPassword` Livewire component.
 */
class ResetUserPassword implements ResetsUserPasswords
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Authenticatable $user, array $input): void
    {
        $this->reset($user, $input);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function reset(Authenticatable $user, array $input): void
    {
        throw_unless($user instanceof User, RuntimeException::class, 'Expected an Nvade\Numerosis\Models\User instance.');

        $data = ResetPasswordData::validateAndCreate($input);

        $user->forceFill([
            'password' => Hash::make($data->password),
        ])->save();
    }
}

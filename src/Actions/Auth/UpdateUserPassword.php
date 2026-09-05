<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Auth\UpdatePasswordData;
use Nvade\Numerosis\Models\User;

/**
 * Fortify's `UpdatesUserPasswords` slot, bound via
 * `Fortify::updateUserPasswordsUsing()`. `PasswordController` validates
 * nothing itself, so `update()` must, which `UpdatePasswordData` does on
 * entry, including the `current_password` check the Livewire settings screen
 * already performs inline for itself.
 */
class UpdateUserPassword implements UpdatesUserPasswords
{
    use AsAction;

    public function handle(User $user, string $password): void
    {
        $this->applyPassword($user, $password);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        $data = UpdatePasswordData::validateAndCreate($input);

        $this->applyPassword($user, $data->password);
    }

    private function applyPassword(User $user, string $password): void
    {
        $user->update([
            'password' => Hash::make($password),
        ]);
    }
}

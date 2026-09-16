<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Auth\UpdatePasswordData;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Events\Auth\PasswordChanged;
use Nvade\Numerosis\Models\User;

/**
 * Fortify's `UpdatesUserPasswords` slot, bound via
 * `Fortify::updateUserPasswordsUsing()`. `PasswordController` validates
 * nothing itself, so this must, and `handle()` forwards to `update()` so
 * `::run()` cannot reach the write without the `current_password` and strength
 * checks.
 */
class UpdateUserPassword implements UpdatesUserPasswords
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, array $input): void
    {
        $this->update($user, $input);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        /** @var array<string, mixed> $validated */
        $validated = Validator::make($input, UpdatePasswordData::rulesFor($user))->validate();

        $data = UpdatePasswordData::from($validated);

        $user->update([
            'password' => Hash::make($data->password),
        ]);

        event(new PasswordChanged(Context::current()->guard(), $user->id));
    }
}

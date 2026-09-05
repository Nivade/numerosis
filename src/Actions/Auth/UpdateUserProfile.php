<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Auth\UpdateProfileData;
use Nvade\Numerosis\Models\User;
use Spatie\LaravelData\Optional;

/**
 * Fortify's `UpdatesUserProfileInformation` slot, bound via
 * `Fortify::updateUserProfileInformationUsing()`. `ProfileInformationController`
 * validates nothing itself, so `update()` must, which `UpdateProfileData` does
 * on entry.
 */
class UpdateUserProfile implements UpdatesUserProfileInformation
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, array $data): void
    {
        $this->update($user, $data);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        $data = UpdateProfileData::validateAndCreate($input);

        if (! $data->name instanceof Optional) {
            $user->name = $data->name;
        }

        if (! $data->email instanceof Optional) {
            $this->ensureEmailIsAvailable($user, $data->email);

            $user->email = $data->email;
        }

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
            $user->sendEmailVerificationNotification();
        }

        $user->save();
    }

    private function ensureEmailIsAvailable(User $user, string $email): void
    {
        $taken = $user->newQuery()
            ->where('email', $email)
            ->whereKeyNot($user->getKey())
            ->exists();

        throw_if($taken, ValidationException::withMessages([
            'email' => trans('validation.unique', ['attribute' => 'email']),
        ]));
    }
}

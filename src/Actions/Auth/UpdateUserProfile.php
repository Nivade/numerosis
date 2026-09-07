<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Auth\UpdateProfileData;
use Nvade\Numerosis\Models\User;
use Spatie\LaravelData\Optional;

/**
 * Fortify's `UpdatesUserProfileInformation` slot, bound via
 * `Fortify::updateUserProfileInformationUsing()`. `ProfileInformationController`
 * validates nothing itself, so this must, and `handle()` forwards to `update()`
 * so `::run()` cannot reach the write unvalidated.
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
        /** @var array<string, mixed> $validated */
        $validated = Validator::make($input, UpdateProfileData::rulesFor($user))->validate();

        $data = UpdateProfileData::from($validated);

        if (! $data->name instanceof Optional) {
            $user->name = $data->name;
        }

        if (! $data->email instanceof Optional) {
            $user->email = $data->email;
        }

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
            $user->sendEmailVerificationNotification();
        }

        $user->save();
    }
}

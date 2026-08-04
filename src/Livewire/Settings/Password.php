<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Settings;

use Nvade\Numerosis\Actions\Auth\UpdateUserPassword;
use Nvade\Numerosis\Concerns\RequiresAuthenticatedUser;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Password extends Component
{
    use RequiresAuthenticatedUser;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        /** @var Collection<string, array<int, PasswordRule|string>> $rules */
        $rules = new Collection([
            'password' => ['required', 'string', PasswordRule::defaults(), 'confirmed'],
        ]);

        $user = $this->authenticatedUser();

        if ($user->password) {
            $rules->prepend(['required', 'string', 'current_password'], 'current_password');
        }

        try {
            $validated = $this->validate($rules->all());
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        UpdateUserPassword::run($user, $validated['password']);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }
}

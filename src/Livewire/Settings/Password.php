<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Settings;

use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Auth\UpdateUserPassword;
use Nvade\Numerosis\Concerns\RequiresAuthenticatedUser;

#[Layout('numerosis-layouts::app')]
class Password extends Component
{
    use RequiresAuthenticatedUser;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function updatePassword(): void
    {
        $user = $this->authenticatedUser();

        try {
            UpdateUserPassword::run($user, [
                'current_password' => $this->current_password,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }

    public function render(): View
    {
        return view('numerosis::livewire.settings.password');
    }
}

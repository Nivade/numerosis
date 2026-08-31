<?php

declare(strict_types=1);

namespace Nvade\NumerosisAuthUi\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;

#[Layout('layouts::auth')]
class ForgotPassword extends Component
{
    public string $email = '';

    public ?string $turnstileResponse = null;

    /**
     * Send a password reset link to the provided email address.
     */
    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'turnstileResponse' => TurnstileFeature::rules(),
        ]);

        Password::sendResetLink($this->only('email'));

        Session::flash('status', __('A reset link will be sent if the account exists.'));
    }

    public function render(): View
    {
        return view('numerosis::livewire.auth.forgot-password');
    }
}

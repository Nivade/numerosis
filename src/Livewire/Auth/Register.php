<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Auth\RegisterUser;
use Nvade\Numerosis\Contracts\Auth\ResolvesPostLoginRedirectUrl;
use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Support\Numerosis;

#[Layout('layouts::auth')]
class Register extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $turnstileResponse = null;

    /**
     * Handle an incoming registration request.
     */
    public function register(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.Numerosis::model(CentralUser::class)],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'turnstileResponse' => TurnstileFeature::rules(),
        ]);

        /** @var array{name: string, email: string, password: string} $validated */
        $validated = [
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
        ];

        RegisterUser::run($validated);

        $this->redirect(app(ResolvesPostLoginRedirectUrl::class)->url(), navigate: true);
    }

    public function render(): View
    {
        return view('numerosis::livewire.auth.register');
    }
}

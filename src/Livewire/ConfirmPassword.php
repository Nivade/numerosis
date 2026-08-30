<?php

declare(strict_types=1);

namespace Nvade\NumerosisAuthUi\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Features\Ui\AccountPagesFeature;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Routes\RouteNames;

#[Layout('layouts::auth')]
class ConfirmPassword extends Component
{
    public string $password = '';

    /**
     * Confirm the current user's password.
     */
    public function confirmPassword(): void
    {

        $this->validate([
            'password' => ['required', 'string'],
        ]);

        $user = Auth::user();

        if (! $user) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        if (! Auth::guard('web')->validate([
            'email' => $user->email,
            'password' => $this->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        Session::put(['auth.password_confirmed_at' => time()]);

        $default = Features::enabled(AccountPagesFeature::NAME) ? RouteNames::tenantsMine() : RouteNames::home();

        $this->redirectIntended(default: route($default, absolute: false), navigate: true);
    }

    public function render(): View
    {
        return view('numerosis::livewire.auth.confirm-password');
    }
}

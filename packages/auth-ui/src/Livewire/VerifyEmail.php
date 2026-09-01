<?php

declare(strict_types=1);

namespace Nvade\NumerosisAuthUi\Livewire;

use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Livewire\Actions\Logout;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Routes\RouteNames;
use Nvade\Numerosis\Support\Ui\AccountPages;

#[Layout('layouts::auth')]
class VerifyEmail extends Component
{
    /**
     * Send an email verification notification to the user.
     */
    public function sendVerification(): void
    {
        $user = GetAuthenticatedUser::run();

        if (! $user || $user->hasVerifiedEmail()) {
            $default = Features::enabled(AccountPages::FEATURE) ? RouteNames::tenantsMine() : RouteNames::home();

            $this->redirectIntended(default: route($default, absolute: false), navigate: true);

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }

    public function render(): View
    {
        return view('numerosis::livewire.auth.verify-email');
    }
}

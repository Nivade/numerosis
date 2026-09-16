<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Settings;

use Illuminate\Auth\AuthenticationException;
use Illuminate\View\View;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\CentralUser;

/**
 * Enrolment for the authenticator app, on the central account. Password
 * confirmation is `password.confirm.if-set` on the route, re-applied to every
 * Livewire update by `Livewire::addPersistentMiddleware()`.
 */
#[Layout('numerosis-layouts::app')]
class TwoFactor extends Component
{
    public bool $confirming = false;

    public bool $showingRecoveryCodes = false;

    public string $code = '';

    public function enable(EnableTwoFactorAuthentication $enable): void
    {
        $enable($this->centralUser());

        $this->confirming = true;
    }

    public function confirm(ConfirmTwoFactorAuthentication $confirm): void
    {
        $confirm($this->centralUser(), $this->code);

        $this->reset('code');
        $this->confirming = false;
        $this->showingRecoveryCodes = true;

        $this->dispatch('two-factor-confirmed');
    }

    public function cancel(DisableTwoFactorAuthentication $disable): void
    {
        $disable($this->centralUser());

        $this->reset('code', 'confirming', 'showingRecoveryCodes');
    }

    public function disable(DisableTwoFactorAuthentication $disable): void
    {
        $disable($this->centralUser());

        $this->reset('code', 'confirming', 'showingRecoveryCodes');

        $this->dispatch('two-factor-disabled');
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generate): void
    {
        $generate($this->centralUser());

        $this->showingRecoveryCodes = true;
    }

    public function showRecoveryCodes(): void
    {
        $this->showingRecoveryCodes = true;
    }

    public function render(): View
    {
        $user = $this->centralUser();
        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;

        return view('numerosis::livewire.settings.two-factor', [
            'enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'pending' => $pending,
            'qrCode' => $pending ? $user->twoFactorQrCodeSvg() : null,
            'secret' => $pending ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret) : null,
            'recoveryCodes' => $this->showingRecoveryCodes && $user->two_factor_recovery_codes !== null
                ? $user->recoveryCodes()
                : [],
        ]);
    }

    /**
     * The central account, whatever guard the request arrived on: every login
     * checks its credentials against that provider, so it is the only account
     * a factor can be challenged on.
     *
     * @throws AuthenticationException
     */
    private function centralUser(): CentralUser
    {
        $user = GetAuthenticatedUser::run(Context::Central->guard());

        throw_if(! $user instanceof CentralUser, AuthenticationException::class);

        return $user;
    }
}

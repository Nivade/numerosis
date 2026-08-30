<?php

declare(strict_types=1);

namespace Nvade\NumerosisAuthUi\Livewire;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Nvade\NumerosisAuthUi\Concerns\ThrottlesLoginAttempts;
use Nvade\Numerosis\Contracts\Auth\AuthenticatesLoginCandidate;
use Nvade\Numerosis\Contracts\Auth\ResolvesLoginCandidate;
use Nvade\Numerosis\Contracts\Auth\ResolvesPostLoginRedirectUrl;
use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Override;
use Spatie\OneTimePasswords\Livewire\OneTimePasswordComponent;
use Spatie\OneTimePasswords\Rules\OneTimePasswordRule;

#[Layout('layouts::auth')]
class PasswordlessLogin extends OneTimePasswordComponent
{
    use ThrottlesLoginAttempts;

    public bool $remember = false;

    public ?string $turnstileResponse = null;

    #[Override]
    public function submitEmail(): void
    {
        $this->validate([
            'turnstileResponse' => TurnstileFeature::rules(),
        ]);

        parent::submitEmail();
    }

    /**
     * The one-time password is the only thing standing between a submitted
     * email address and a session, so it is verified here and never assumed
     * from `$this->displayingEmailForm` having been flipped: `email` is a
     * plain public property and every public method on a Livewire component
     * is directly invokable by the client, so reaching this method proves
     * nothing about having passed submitEmail() first.
     *
     * Failed attempts are rate limited by email+IP. Without it the code is a
     * six-digit number an attacker may guess as fast as they can issue
     * requests; the parent's own limiter only throttles code *sending*.
     */
    #[Override]
    public function submitOneTimePassword(): void
    {
        $this->ensureIsNotRateLimited();

        $user = $this->findUser();

        if (! $user) {
            $this->hitLoginThrottle();

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        try {
            $this->validate([
                'oneTimePassword' => ['required', new OneTimePasswordRule($user)],
            ]);
        } catch (ValidationException $e) {
            $this->hitLoginThrottle();

            throw $e;
        }

        $this->clearLoginThrottle();

        $this->authenticate($user);

        $this->redirect(resolve(ResolvesPostLoginRedirectUrl::class)->url());
    }

    #[Override]
    protected function findUser(): ?Authenticatable
    {
        return resolve(ResolvesLoginCandidate::class)->find((string) $this->email);
    }

    #[Override]
    public function authenticate(Authenticatable $user): void
    {
        resolve(AuthenticatesLoginCandidate::class)->authenticate($user, $this->remember);
    }

    protected function throttleIdentifier(): string
    {
        return (string) $this->email;
    }

    #[Override]
    public function render(): View
    {
        /** @var view-string $view */
        $view = 'numerosis::livewire.auth.passwordless-login.'.$this->showViewName();

        return view($view);
    }
}

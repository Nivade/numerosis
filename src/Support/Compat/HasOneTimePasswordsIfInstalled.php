<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Compat;

/**
 * See {@see FilamentUserContract} for why this indirection exists — same
 * eager-`use trait` problem, for `spatie/laravel-one-time-passwords`. A
 * consumer without that package installed gets a base model that compiles
 * fine and simply has no passwordless-login methods; nothing in this
 * package calls them unless {@see \Nvade\NumerosisAuthUi\Livewire\PasswordlessLogin}
 * (or a host's own OTP code) is reached, which requires the package anyway.
 */
if (trait_exists(\Spatie\OneTimePasswords\Models\Concerns\HasOneTimePasswords::class)) {
    trait HasOneTimePasswordsIfInstalled
    {
        use \Spatie\OneTimePasswords\Models\Concerns\HasOneTimePasswords;
    }
} else {
    trait HasOneTimePasswordsIfInstalled {}
}

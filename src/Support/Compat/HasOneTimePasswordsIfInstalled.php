<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Compat;

/**
 * Conditional-definition shim for `spatie/laravel-one-time-passwords`. Without
 * that package a consumer gets a base model that compiles and has no
 * passwordless-login methods, which nothing calls unless a surface requiring
 * the package is reached.
 *
 * @see \Nvade\Numerosis\Features\Auth\OneTimePasswordFeature
 */
if (trait_exists(\Spatie\OneTimePasswords\Models\Concerns\HasOneTimePasswords::class)) {
    trait HasOneTimePasswordsIfInstalled
    {
        use \Spatie\OneTimePasswords\Models\Concerns\HasOneTimePasswords;
    }
} else {
    trait HasOneTimePasswordsIfInstalled {}
}

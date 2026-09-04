<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Compat;

/**
 * Conditional-definition shim: `use <Trait>` resolves its target eagerly, at
 * class-declaration time, so a base model composing a trait from an optional
 * package cannot even autoload without it. Making the *trait itself*
 * conditional is what keeps the package optional — see
 * `.ai/rules/optional-dependencies.md`. Here, for `spatie/laravel-one-time-passwords`. A
 * consumer without that package installed gets a base model that compiles
 * fine and simply has no passwordless-login methods; nothing in this
 * package calls them unless a passwordless-login surface (Phase 5 of
 * `.claude/plans/archive/humming-nibbling-flame.md`'s `OneTimePasswordFeature`, or a
 * host's own OTP code) is reached, which requires the package anyway.
 */
if (trait_exists(\Spatie\OneTimePasswords\Models\Concerns\HasOneTimePasswords::class)) {
    trait HasOneTimePasswordsIfInstalled
    {
        use \Spatie\OneTimePasswords\Models\Concerns\HasOneTimePasswords;
    }
} else {
    trait HasOneTimePasswordsIfInstalled {}
}

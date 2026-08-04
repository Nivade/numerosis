<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Turnstile;

use Nvade\Numerosis\Contracts\NamedFeature;
use RyanChandler\LaravelCloudflareTurnstile\Rules\Turnstile as TurnstileRule;

/**
 * The single on/off switch for Turnstile. `<x-turnstile-field>` and
 * `<x-turnstile-scripts>` (resources/views/components/) are always
 * discoverable Blade components — they never fail to resolve — but both
 * render nothing unless `self::isEnabled()` is true, so a Livewire form
 * referencing `<x-turnstile-field />` unconditionally never breaks
 * regardless of whether this feature is wired up.
 *
 * `isEnabled()` is just "was `bootstrap()` called" — and that only happens
 * if this class is listed in `config('numerosis.features')`. Presence in
 * that array *is* the toggle; there is no separate enabled/disabled flag.
 * Comment this class out there to remove Turnstile from the app entirely —
 * markup, validation and script tag together. Cloudflare credentials
 * (`key`/`secret`) stay in `config('services.turnstile')`.
 *
 * `$bootstrapped` is a plain static, not container-scoped, so — same
 * process-wide-static caveat `.claude/rules/testing.md` already documents
 * for `Tenant::unsetEventDispatcher()` — it is set once per PHP process and
 * survives every later test's fresh Application boot. `forceForTesting()`
 * exists so a test can still assert "disabled" behaviour without a config
 * flag to flip; call it with `null` in `tearDown()`/at the end of the test
 * to stop it leaking into whichever test runs next.
 */
class TurnstileFeature implements NamedFeature
{
    public const NAME = 'turnstile';

    private static bool $bootstrapped = false;

    private static ?bool $forcedForTesting = null;

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        self::$bootstrapped = true;
    }

    public static function isEnabled(): bool
    {
        return self::$forcedForTesting ?? self::$bootstrapped;
    }

    public static function forceForTesting(?bool $enabled): void
    {
        self::$forcedForTesting = $enabled;
    }

    /**
     * The validation rule for every form's `turnstileResponse` field —
     * empty when Turnstile is off, so nothing about the field is enforced.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return self::isEnabled() ? ['required', new TurnstileRule] : [];
    }
}

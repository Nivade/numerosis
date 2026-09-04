<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Turnstile;

use Nvade\Numerosis\Contracts\NamedFeature;
use RyanChandler\LaravelCloudflareTurnstile\Rules\Turnstile as TurnstileRule;

/**
 * Cloudflare Turnstile: the markup, the script tag and the validation rule
 * together. Remove it from `numerosis.features` to drop all three.
 *
 * `<x-numerosis::turnstile-field />` always resolves and simply renders
 * nothing when this is off, so a form may reference it unconditionally.
 * Credentials stay in `config('services.turnstile')`.
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

    /**
     * The single `class_exists()` seam for `ryangjchandler/laravel-cloudflare-turnstile`
     * — a `suggest`, not a `require`. Every call site asks this rather than
     * probing the package itself, per `.ai/rules/optional-dependencies.md`
     * ("one seam per optional package, not one per call site"). Without it,
     * `rules()` would return `[new TurnstileRule]` and fatal on a
     * class-not-found instead of degrading to no validation.
     */
    public static function isEnabled(): bool
    {
        return (self::$forcedForTesting ?? self::$bootstrapped) && class_exists(TurnstileRule::class);
    }

    /**
     * Override the switch in a test. Pass null in teardown to restore it —
     * this is process-wide and otherwise leaks into the next test.
     */
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

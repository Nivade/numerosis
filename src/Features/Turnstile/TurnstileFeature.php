<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Turnstile;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Features\Concerns\IsNamedFeature;
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
    use IsNamedFeature;

    public const NAME = 'turnstile';

    /**
     * The validation rule for every form's `turnstileResponse` field. Empty
     * when Turnstile is off, so nothing about the field is enforced.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return self::available() ? ['required', new TurnstileRule] : [];
    }
}

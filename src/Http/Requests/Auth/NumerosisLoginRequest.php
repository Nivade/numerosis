<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Auth;

use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\LoginRequest;
use Nvade\Numerosis\Features\Auth\OneTimePasswordFeature;
use Override;

/**
 * Bound over Fortify's own `LoginRequest` in
 * `NumerosisServiceProvider::registerFortify()` — `AuthenticatedSessionController::store()`
 * type-hints the concrete class, so a subclass binding still resolves.
 *
 * Fortify's own `rules()` makes `password` unconditionally `required`, which
 * runs as a `FormRequest` *before* `authenticateThrough()`'s pipeline even
 * starts — so `RedirectIfOneTimePasswordAuthenticatable` never gets a chance
 * to redirect an email-only submission, it 422s first.
 * `OneTimePasswordFeature::enabled()` replaces the password step with the
 * challenge screen rather than adding to it (see that class's docblock), so
 * `password` has to become optional at the same seam.
 */
class NumerosisLoginRequest extends LoginRequest
{
    /**
     * @return array<string, list<string>>
     */
    #[Override]
    public function rules(): array
    {
        return [
            Fortify::username() => ['required', 'string'],
            'password' => OneTimePasswordFeature::available() ? ['sometimes', 'string'] : ['required', 'string'],
            'remember' => ['sometimes'],
        ];
    }
}

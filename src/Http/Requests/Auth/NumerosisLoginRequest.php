<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Auth;

use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\LoginRequest;
use Nvade\Numerosis\Features\Auth\OneTimePasswordFeature;
use Override;

/**
 * Bound over Fortify's own `LoginRequest` in
 * `NumerosisServiceProvider::registerFortify()`. Fortify makes `password`
 * unconditionally `required`, and a `FormRequest` validates before
 * `authenticateThrough()`'s pipeline starts, so an email-only submission would
 * 422 before `RedirectIfOneTimePasswordAuthenticatable` could redirect it.
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

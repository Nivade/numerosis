<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Auth;

use Laravel\Fortify\Http\Requests\VerifyEmailRequest;
use Nvade\Numerosis\Models\User;
use Override;

/**
 * Bound over Fortify's own `VerifyEmailRequest` in
 * `NumerosisServiceProvider::packageBooted()`. `EmailVerificationFeature`
 * builds the signed URL's `id` from `getGlobalIdentifierKey()` and never the
 * primary key Fortify's `authorize()` compares against, so this override
 * matches it.
 */
class NumerosisVerifyEmailRequest extends VerifyEmailRequest
{
    #[Override]
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        if (! hash_equals((string) $user->getGlobalIdentifierKey(), (string) $this->route('id'))) {
            return false;
        }

        return hash_equals(sha1((string) $user->getEmailForVerification()), (string) $this->route('hash'));
    }
}

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests\Auth;

use Laravel\Fortify\Http\Requests\VerifyEmailRequest;
use Override;

/**
 * Bound over Fortify's own `VerifyEmailRequest` in
 * `NumerosisServiceProvider::packageBooted()` — `VerifyEmailController`
 * type-hints the concrete class, so a subclass binding still resolves.
 * `EmailVerificationFeature` builds the signed URL's `id` from
 * `getGlobalIdentifierKey()`, not the primary key Fortify's own
 * `authorize()` compares against; this override matches it.
 */
class NumerosisVerifyEmailRequest extends VerifyEmailRequest
{
    #[Override]
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        if (! hash_equals((string) $user->getGlobalIdentifierKey(), (string) $this->route('id'))) {
            return false;
        }

        return hash_equals(sha1((string) $user->getEmailForVerification()), (string) $this->route('hash'));
    }
}

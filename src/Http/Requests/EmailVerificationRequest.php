<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Requests;

use Illuminate\Foundation\Auth\EmailVerificationRequest as BaseEmailVerificationRequest;
use Override;

class EmailVerificationRequest extends BaseEmailVerificationRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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

<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;

/**
 * Only used under IdentificationMode::CustomDomain, on the wizard's separate
 * custom-domain field — see Nvade\Numerosis\Rules\DomainIsAvailable, its
 * counterpart for the id/slug field every mode shares.
 */
class CustomDomainIsAvailable implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Not a `(string)` cast: an array or object here is a fatal rather
        // than a validation failure, and a client controls this value.
        if (! is_string($value)) {
            $fail('The domain format is invalid.');

            return;
        }

        try {
            resolve(TenantDomainPolicy::class)->assertCustomDomainAvailable($value);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $fail($message);
                }
            }
        }
    }
}

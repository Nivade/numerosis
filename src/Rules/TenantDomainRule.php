<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;

/**
 * Adapts `TenantDomainPolicy` for a `rules()` array, so the wizard's own
 * validation stays in step with what checkout enforces, with no second
 * hand-copied regex. Subclasses choose which assertion to run.
 */
abstract class TenantDomainRule implements ValidationRule
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
            $this->assertAvailable(resolve(TenantDomainPolicy::class), $value);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $fail($message);
                }
            }
        }
    }

    abstract protected function assertAvailable(TenantDomainPolicy $policy, string $domain): void;
}

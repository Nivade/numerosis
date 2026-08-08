<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;

/**
 * Adapts TenantDomainPolicy (format, reserved words, already a live tenant)
 * for use in a rules() array, so the wizard's own validation stays in step
 * with what ReserveTenantDomain checks at checkout time instead of a second,
 * hand-copied regex. Deliberately does not cover pending_tenant_provisions —
 * see Nvade\Numerosis\Services\Tenancy\DefaultTenantDomainPolicy.
 */
class DomainIsAvailable implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            resolve(TenantDomainPolicy::class)->assertAvailable((string) $value);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $fail($message);
                }
            }
        }
    }
}

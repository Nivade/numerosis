<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;

/**
 * Checks a requested identifier's format, reserved words, and whether a live
 * tenant already holds it, plus a second check for the custom domain under
 * `IdentificationMode::CustomDomain`. Domains merely reserved during checkout
 * are not considered here: that rule must let the same user retry their own
 * reservation, so it lives with the reservation.
 */
class DefaultTenantDomainPolicy implements TenantDomainPolicy
{
    /** @var list<string> */
    private const array RESERVED = [
        'www', 'admin', 'api', 'app', 'mail', 'ftp', 'localhost',
        'staging', 'dev', 'test', 'support', 'help', 'billing', 'status',
    ];

    public function assertAvailable(string $domain): void
    {
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]$/', $domain)) {
            throw ValidationException::withMessages([
                'domain' => 'The domain format is invalid.',
            ]);
        }

        if (in_array(strtolower($domain), self::RESERVED, true)) {
            throw ValidationException::withMessages([
                'domain' => 'This domain is reserved.',
            ]);
        }

        if ($this->alreadyTaken($domain)) {
            throw ValidationException::withMessages([
                'domain' => 'This domain is already taken.',
            ]);
        }
    }

    public function assertCustomDomainAvailable(string $domain): void
    {
        if (! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain)) {
            throw ValidationException::withMessages([
                'customDomain' => 'The domain format is invalid.',
            ]);
        }

        $domainClass = Numerosis::model(Domain::class);

        if ($domainClass::where('domain', $domain)->exists()) {
            throw ValidationException::withMessages([
                'customDomain' => 'This domain is already taken.',
            ]);
        }
    }

    /**
     * `assertAvailable()`'s $domain is `tenants.id` under every mode except
     * Subdomain, where it is the label a `domains` row is keyed by
     * (`domains.id`, concatenated with the apex to form `domains.domain`).
     * Checking the wrong table for the current mode lets a taken identifier
     * through, or rejects a free one.
     */
    private function alreadyTaken(string $domain): bool
    {
        if (IdentificationMode::current() === IdentificationMode::Subdomain) {
            $domainClass = Numerosis::model(Domain::class);

            return $domainClass::where('domain', $domain.'.'.Config::string('numerosis.domains.apex'))->exists();
        }

        $tenantClass = Numerosis::model(Tenant::class);

        return $tenantClass::where('id', $domain)->exists();
    }
}

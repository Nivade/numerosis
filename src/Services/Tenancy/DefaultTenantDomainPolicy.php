<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Support\Numerosis;

/**
 * Checks format, reserved words, and whether the domain already belongs to a
 * live tenant. Deliberately does not check pending_tenant_provisions: that
 * table's "already claimed by someone else" rule has same-user-retry
 * idempotency semantics (see ReserveTenantDomain and
 * .claude/rules/tenant-provisioning.md) that must not be duplicated here.
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

        $domainClass = Numerosis::model(Domain::class);

        if ($domainClass::where('domain', $domain.'.'.Config::string('numerosis.domains.apex'))->exists()) {
            throw ValidationException::withMessages([
                'domain' => 'This domain is already taken.',
            ]);
        }
    }
}

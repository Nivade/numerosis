<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Support\Numerosis;

/**
 * Checks a requested domain's format, reserved words, and whether a live
 * tenant already holds it.
 *
 * Does not consider domains merely reserved during checkout — that rule has
 * to let the same user retry their own reservation, and lives with the
 * reservation itself.
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

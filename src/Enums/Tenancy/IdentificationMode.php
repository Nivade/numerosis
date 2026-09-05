<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenancy;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;

/**
 * How a request is matched to a tenant, selected by
 * `numerosis.tenancy.identification.mode`.
 */
enum IdentificationMode: string
{
    case Subdomain = 'subdomain';
    case CustomDomain = 'custom_domain';
    case Path = 'path';

    /**
     * Reached from `bootstrap/app.php`'s `withMiddleware(...)` before
     * `RegisterFacades` has run, so `Config::string()` would throw `A facade
     * root has not been set` fatally. The default is safe to fall back to,
     * because `NumerosisServiceProvider::registerMiddleware()` re-registers
     * the real value from `packageBooted()` once config is loaded.
     */
    public static function current(): self
    {
        if (Facade::getFacadeApplication() === null) {
            return self::Subdomain;
        }

        return self::from(Config::string(
            'numerosis.tenancy.identification.mode',
            self::Subdomain->value,
        ));
    }

    /**
     * Whether this mode identifies tenants through a row in the `domains`
     * table at all. Path mode resolves purely by tenant id, so no `Domain`
     * row is ever created or read for it.
     */
    public function usesDomainRecord(): bool
    {
        return $this !== self::Path;
    }
}

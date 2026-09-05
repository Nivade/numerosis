<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenancy;

use Illuminate\Support\Facades\Config;

/**
 * How a request is matched to a tenant, selected by
 * `numerosis.tenancy.identification.mode`.
 */
enum IdentificationMode: string
{
    case Subdomain = 'subdomain';
    case CustomDomain = 'custom_domain';
    case Path = 'path';

    public static function current(): self
    {
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

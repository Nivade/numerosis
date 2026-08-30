<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenancy;

use Illuminate\Support\Facades\Config;

/**
 * How a request is matched to a tenant. See config('numerosis.tenancy.identification.mode')'s
 * docblock in config/numerosis.php for what each mode means; see
 * .claude/rules/identification-modes.md for the mechanics each one relies
 * on and the one thing (Path mode's route-parameter conflict with Filament's
 * own tenant resolution) that isn't provable by this repo's test harness.
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

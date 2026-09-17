<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Facades;

use Illuminate\Support\Facades\Facade;
use Nvade\Numerosis\Contracts\Billing\Entitlements as EntitlementsContract;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * @method static bool allows(string $capability, ?Tenant $tenant = null)
 * @method static int|null limit(string $capability, ?Tenant $tenant = null)
 * @method static int used(string $capability, ?Tenant $tenant = null)
 * @method static int|null remaining(string $capability, ?Tenant $tenant = null)
 * @method static int consume(string $capability, int $amount = 1, ?Tenant $tenant = null)
 * @method static void assertAllowed(string $capability, ?Tenant $tenant = null)
 *
 * @see EntitlementsContract
 */
class Entitlements extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EntitlementsContract::class;
    }
}

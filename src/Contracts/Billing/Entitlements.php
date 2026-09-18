<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Nvade\Numerosis\Exceptions\Billing\EntitlementDenied;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * `Feature` in this package means a code-level switch in
 * `config('numerosis.features')`, and `PlanFeature` means plan copy in the
 * database. This is neither, which is why it is not called either.
 */
interface Entitlements
{
    public const string SEATS = 'seats';

    public function allows(string $capability, ?Tenant $tenant = null): bool;

    /** Null when the plan puts no limit on it. */
    public function limit(string $capability, ?Tenant $tenant = null): ?int;

    public function used(string $capability, ?Tenant $tenant = null): int;

    /** Null when uncapped; never negative, since a downgrade can leave usage above the limit. */
    public function remaining(string $capability, ?Tenant $tenant = null): ?int;

    /** @throws EntitlementDenied */
    public function consume(string $capability, int $amount = 1, ?Tenant $tenant = null): int;

    public function assertAllowed(string $capability, ?Tenant $tenant = null): void;
}

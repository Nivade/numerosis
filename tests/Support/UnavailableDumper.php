<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseDumper;
use Nvade\Numerosis\Exceptions\Tenancy\TenantBackupFailed;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/** A dumper whose binary is missing, which is what the install doctor reports. */
class UnavailableDumper implements TenantDatabaseDumper
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function unavailableReason(): ?string
    {
        return '`nothing-here` is not on PATH.';
    }

    public function carriesSchema(): bool
    {
        return true;
    }

    public function dump(TenantWithDatabase $tenant, string $file, int $chunk = 500): void
    {
        throw TenantBackupFailed::dumperUnavailable((string) $this->unavailableReason());
    }

    public function restore(TenantWithDatabase $tenant, string $file): void
    {
        throw TenantBackupFailed::dumperUnavailable((string) $this->unavailableReason());
    }
}

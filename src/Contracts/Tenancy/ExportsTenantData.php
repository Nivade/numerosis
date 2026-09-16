<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * The portable archive a customer receives, as opposed to the physical
 * snapshot {@see TenantDatabaseDumper} writes. Point
 * `numerosis.tenancy.implementations` at your own class to add the tables a
 * host owns, or to write a format of your own.
 */
interface ExportsTenantData
{
    /**
     * @param  string|null  $forGlobalUserId  Narrows the archive to one person's rows.
     * @return string The archive's path on the disk.
     */
    public function export(TenantWithDatabase $tenant, ?string $forGlobalUserId = null, ?string $disk = null): string;
}

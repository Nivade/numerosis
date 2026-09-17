<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Writes and reads back a physical snapshot of one tenant database. The
 * implementation is chosen per driver through `numerosis.tenancy.backup.dumpers`.
 *
 * Restoring is not the same operation as importing a customer's archive — see
 * `Services\Tenancy\TenantDataExporter` for the portable, readable format.
 */
interface TenantDatabaseDumper
{
    /**
     * Whether this dumper can run at all. A dumper that shells out answers
     * false when its binary is missing, which `numerosis:install --verify-only`
     * reports rather than leaving it to be discovered during a purge.
     */
    public function isAvailable(): bool;

    /** What is missing, for the install doctor and the command's own error. */
    public function unavailableReason(): ?string;

    /** Whether an artefact this dumper wrote carries the schema as well as the rows. */
    public function carriesSchema(): bool;

    /** $chunk is the restore batch size a dumper that supports it records for its own artefact. */
    public function dump(TenantWithDatabase $tenant, string $file, int $chunk = 500): void;

    public function restore(TenantWithDatabase $tenant, string $file): void;
}

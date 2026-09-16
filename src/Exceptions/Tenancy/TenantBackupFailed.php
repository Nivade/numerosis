<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Exceptions\Tenancy;

use Nvade\Numerosis\Exceptions\DomainException;

class TenantBackupFailed extends DomainException
{
    public static function unwritable(string $file): self
    {
        return new self("Could not open {$file} for writing.");
    }

    public static function unreadable(string $file): self
    {
        return new self("Could not read the backup artefact at {$file}.");
    }

    public static function missingSchema(string $table): self
    {
        return new self("The artefact carries rows for `{$table}` but no schema for it. Restore into a migrated database instead.");
    }

    public static function targetNotEmpty(string $tenantId): self
    {
        return new self("Tenant {$tenantId} already holds rows. Pass --force to overwrite them.");
    }

    public static function dumperUnavailable(string $reason): self
    {
        return new self("The configured backup dumper cannot run: {$reason}");
    }

    public static function artefactMissing(string $path): self
    {
        return new self("No backup artefact at {$path}.");
    }
}

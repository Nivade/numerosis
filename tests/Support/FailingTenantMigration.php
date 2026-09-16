<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

/**
 * Which tenant the hostile fixture migration refuses to migrate. A static
 * rather than a config key because the migration file runs inside tenancy,
 * where reading test state through config would be one more thing the
 * bootstrappers could have swapped underneath it.
 */
final class FailingTenantMigration
{
    public static ?string $tenantId = null;

    private function __construct() {}

    public static function reset(): void
    {
        self::$tenantId = null;
    }
}

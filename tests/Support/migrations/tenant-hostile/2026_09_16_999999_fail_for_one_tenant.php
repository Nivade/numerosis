<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Nvade\Numerosis\Tests\Support\FailingTenantMigration;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Fails for whichever tenant `FailingTenantMigration::$tenantId` names, so a
 * test can watch one tenant fail without stopping the rest of the fleet.
 *
 * Named to sort before every other fixture: a migration that ran before the
 * failure would still be applied, which is not what the test is asserting.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return;
        }

        throw_if(
            $tenant->getTenantKey() === FailingTenantMigration::$tenantId,
            new RuntimeException('this tenant cannot be migrated'),
        );
    }

    public function down(): void {}
};

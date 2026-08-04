<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Must be a real column, not left to fold into `data`:
     * `Tenant::getCustomColumns()` has to name it or `EnsureTenantSubscriptionActive`
     * and `tenancy:prune-orphaned-databases` (both SQL-level reads) silently
     * see nothing — see .claude/rules/tenant-provisioning.md.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('suspended_at');
        });
    }
};

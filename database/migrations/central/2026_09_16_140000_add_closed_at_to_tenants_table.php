<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `Tenant::getCustomColumns()` has to name this column. Left to fold into
     * `data`, it is invisible to `EnsureTenantSubscriptionActive` and
     * `tenancy:prune-orphaned-databases`, which both read it at SQL level.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });
    }
};

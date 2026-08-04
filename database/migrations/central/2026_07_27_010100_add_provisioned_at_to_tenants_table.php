<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('provisioned_at')->nullable();
        });

        // Existing tenants are already provisioned. Without this backfill they
        // would every one render as a permanently "provisioning" spinner, since
        // readiness is now defined as provisioned_at being set.
        DB::table('tenants')->update([
            'provisioned_at' => DB::raw('created_at'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('provisioned_at');
        });
    }
};

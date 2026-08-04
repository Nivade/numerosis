<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors database/migrations/tenant/2026_05_01_000003_add_attribute_changes_to_activity_log_table.php,
 * which was only ever applied to tenant databases. spatie/laravel-activitylog
 * v5 writes changes to `attribute_changes` rather than v4's `properties`, so
 * without this column every activity written on the central connection fails
 * with "Unknown column 'attribute_changes' in 'field list'" — logging a central
 * model is not an exotic path, it is what the admin panel does.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('activity_log') && ! Schema::hasColumn('activity_log', 'attribute_changes')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->json('attribute_changes')->nullable()->after('properties');
            });
        }
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn('attribute_changes');
        });
    }
};

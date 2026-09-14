<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant databases' `attribute_changes` column, for the central one.
 * spatie/laravel-activitylog v5 writes changes there rather than to v4's
 * `properties`, so without it every activity logged on the central connection
 * fails with "Unknown column 'attribute_changes' in 'field list'".
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

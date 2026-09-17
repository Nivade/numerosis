<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `user_id` holds whichever guard was ambient when the row was written, so it
 * cannot identify the person across the central and tenant guards. The global
 * id can, and it is what narrows `DatabaseSessionRegistry`'s scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sessions') || Schema::hasColumn('sessions', 'global_user_id')) {
            return;
        }

        Schema::table('sessions', function (Blueprint $table) {
            $table->string('global_user_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sessions') || ! Schema::hasColumn('sessions', 'global_user_id')) {
            return;
        }

        Schema::table('sessions', function (Blueprint $table) {
            $table->dropIndex(['global_user_id']);
            $table->dropColumn('global_user_id');
        });
    }
};

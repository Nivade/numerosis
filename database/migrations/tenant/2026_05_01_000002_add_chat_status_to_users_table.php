<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // No `->after()`: this used to sit after `last_seen_at`, whose
            // migration was deleted along with the presence tracking it fed.
            // Naming a column that no longer exists is a hard MySQL error on
            // a fresh migrate, and column order is cosmetic anyway.
            $table->string('display_status', 50)->nullable();
            $table->string('custom_status_text', 100)->nullable()->after('display_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['display_status', 'custom_status_text']);
        });
    }
};

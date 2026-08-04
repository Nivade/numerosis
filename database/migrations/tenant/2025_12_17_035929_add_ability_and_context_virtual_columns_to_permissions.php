<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('ability')->virtualAs("SUBSTRING_INDEX(name, ' ', 1)");
            $table->string('context')->virtualAs("SUBSTRING_INDEX(name, ' ', -1)");
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('ability');
            $table->dropColumn('context');
        });
    }
};

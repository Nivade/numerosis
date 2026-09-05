<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Config::string('activitylog.table_name'), function (Blueprint $table) {
            $table->string('event')->nullable()->after('subject_type');
        });
    }

    public function down(): void
    {
        /** @var string|null $connection */
        $connection = Config::get('activitylog.database_connection');

        Schema::connection($connection)->table(Config::string('activitylog.table_name'), function (Blueprint $table) {
            $table->dropColumn('event');
        });
    }
};

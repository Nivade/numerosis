<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-activitylog's table, its three published migrations folded
 * into one. `attribute_changes` is added separately, later, since that column
 * is this package's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var string|null $connection */
        $connection = Config::get('activitylog.database_connection');

        Schema::connection($connection)->create(Config::string('activitylog.table_name'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            // Spelled out rather than nullableMorphs() so `event` keeps the
            // position the ALTER that once added it gave it.
            $table->string('subject_type')->nullable();
            $table->string('event')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->index(['subject_type', 'subject_id'], 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
            $table->index('log_name');
        });
    }

    public function down(): void
    {
        /** @var string|null $connection */
        $connection = Config::get('activitylog.database_connection');

        Schema::connection($connection)->dropIfExists(Config::string('activitylog.table_name'));
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Overrides only. Defaults live on `Enums\Notifications\NotificationType`, so a
 * type added later has behaviour without a migration and without a row per user.
 *
 * Keyed by `global_id` rather than by a user id: the preference follows the
 * person across tenants and survives their removal from any one of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->string('global_id')->index();
            $table->string('type');
            $table->boolean('mail')->nullable();
            $table->boolean('database')->nullable();
            $table->timestamps();

            $table->unique(['global_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};

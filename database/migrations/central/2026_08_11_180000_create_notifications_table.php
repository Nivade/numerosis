<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs Laravel's own `database` notification channel, which reads and
 * writes this table for the notifiable model — here `CentralUser`, on the
 * central connection. Central-only: nothing notifies a tenant-connection
 * model through this channel today. If something does later, it needs its
 * own copy of this migration under `database/migrations/tenant/` — same trap
 * as the `one_time_passwords` table documented in `.ai/rules/auth-login.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};

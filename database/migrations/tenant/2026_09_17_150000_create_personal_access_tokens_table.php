<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum's table, in the tenant database rather than the central one. An API
 * token authorizes one person inside one workspace, so a central table would be
 * a token list shared across every tenant — and Sanctum writes to whichever
 * connection is open when a token is created, which inside a tenant request is
 * this one.
 *
 * `ip_allowlist` is this package's addition: a token restricted to known egress
 * addresses, empty meaning unrestricted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->json('ip_allowlist')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The compliance record of one support session: who signed in as whom, where,
 * and for how long. Rows are never deleted, so `token` outlives the
 * `tenant_user_impersonation_tokens` row it names and carries no foreign key
 * to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 128)->index();
            $table->string('tenant_id');
            $table->string('staff_global_id');
            $table->string('target_global_id');
            $table->timestamp('started_at')->nullable()->comment('Null until the token is redeemed');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            $table->foreign('staff_global_id')
                ->references('global_id')
                ->on('users')
                ->onUpdate('cascade')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
    }
};

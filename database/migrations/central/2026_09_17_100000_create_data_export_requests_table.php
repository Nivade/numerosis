<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One subject access request: who asked, what was produced, and whether the
 * single-use link has been spent. The row outlives its artefact, which the
 * retention sweep deletes, so `path` is nullable rather than a foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_export_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('global_user_id');
            $table->string('tenant_id')->nullable()->comment('Set for a whole-tenant export, null for a personal one');
            $table->string('status')->default('pending');
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['global_user_id', 'created_at']);

            $table->foreign('global_user_id')
                ->references('global_id')
                ->on('users')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onUpdate('cascade')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_export_requests');
    }
};

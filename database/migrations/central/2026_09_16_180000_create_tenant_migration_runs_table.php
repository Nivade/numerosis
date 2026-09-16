<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per tenant per fleet migration run. This is what `--resume` reads,
 * what the staff screen renders, and the artefact an operator wants after a
 * bad deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_migration_runs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('run_id')->index();
            $table->string('tenant_id');
            $table->string('status')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('migrations')->nullable()->comment('The migration names this leg applied');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'tenant_id']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onUpdate('cascade')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_migration_runs');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant carries at most one open nomination, so `unique(tenant_id)` lets
 * `NominateTenantOwner` reuse the row rather than grow a queue of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_ownership_nominations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('tenant_id');
            $table->string('nominee_global_id');
            $table->string('nominated_by')->nullable()->comment('Global user id of the nominating owner');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            $table->foreign('nominee_global_id')
                ->references('global_id')
                ->on('users')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            $table->foreign('nominated_by')
                ->references('global_id')
                ->on('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ownership_nominations');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The counter behind both quota reads and billing meters. Central, not
 * per-tenant: a meter a customer audits must not live in a database the same
 * customer can be restored over, and one table answers "across the fleet"
 * without N queries.
 *
 * `period_start` is null for a lifetime counter and the first day of the
 * billing period otherwise; the unique key is what makes the upsert atomic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_usage', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('key');
            $table->date('period_start')->nullable();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'key', 'period_start'], 'tenant_usage_unique');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onUpdate('cascade')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_usage');
    }
};

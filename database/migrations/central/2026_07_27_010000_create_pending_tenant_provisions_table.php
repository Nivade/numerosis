<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pending_tenant_provisions', function (Blueprint $table) {
            $table->string('domain')->primary();
            $table->string('company_name');
            $table->string('global_id')->index();
            $table->string('status')->default('reserved');
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_tenant_provisions');
    }
};

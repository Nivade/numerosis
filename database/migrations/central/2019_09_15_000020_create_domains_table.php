<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * stancl/tenancy's domains table, with a string key rather than the
 * auto-incrementing one it ships: this package's `Domain` model is created
 * alongside a tenant whose own key is a string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('domain', 255)->unique();
            $table->string('tenant_id');

            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};

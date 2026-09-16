<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant migration the template database predates, so a cloned test tenant
 * is genuinely one migration behind. This is what makes the pending query and
 * the fleet command testable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_probes', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_probes');
    }
};

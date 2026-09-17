<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks when the current run of failed checks began. status and
 * last_checked_at are overwritten on every check, so neither can say how
 * long a domain has been failing; this is what the give-up window and the
 * recheck backoff both measure from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->timestamp('failing_since')->nullable()->after('last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn('failing_since');
        });
    }
};

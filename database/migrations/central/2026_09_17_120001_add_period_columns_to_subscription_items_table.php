<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe moved the billing period onto the subscription item, and a meter is
 * reported per period, so the period has to be readable without a Stripe call
 * from a job that may run while Stripe is unreachable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_items', function (Blueprint $table): void {
            $table->timestamp('current_period_start')->nullable()->after('meter_event_name');
            $table->timestamp('current_period_end')->nullable()->after('current_period_start');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_items', function (Blueprint $table): void {
            $table->dropColumn(['current_period_start', 'current_period_end']);
        });
    }
};

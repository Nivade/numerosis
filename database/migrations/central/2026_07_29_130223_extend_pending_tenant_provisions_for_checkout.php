<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Makes the pending row the checkout aggregate rather than just a
     * reservation flag — see .claude/plans/archive/custom-checkout.md, "Data model".
     */
    public function up(): void
    {
        Schema::table('pending_tenant_provisions', function (Blueprint $table) {
            $table->string('payment_plan')->nullable()->after('global_id');
            $table->string('billing_cycle')->nullable()->after('payment_plan');
            $table->string('stripe_setup_intent_id')->nullable()->after('billing_cycle');
            $table->string('stripe_subscription_id')->nullable()->after('stripe_setup_intent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pending_tenant_provisions', function (Blueprint $table) {
            $table->dropColumn([
                'payment_plan',
                'billing_cycle',
                'stripe_setup_intent_id',
                'stripe_subscription_id',
            ]);
        });
    }
};

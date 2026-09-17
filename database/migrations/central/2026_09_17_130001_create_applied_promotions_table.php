<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail of which tenant redeemed what, never the source of truth for
 * a discount: Stripe owns the arithmetic and the expiry. This is what the staff
 * panel and revenue reporting read without a Stripe call per row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applied_promotions', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->nullable();
            $table->string('global_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable();
            $table->string('code');
            $table->string('stripe_promotion_code_id');
            $table->string('stripe_coupon_id');

            // As Stripe reported it at the moment of application: one of the
            // two is null, and neither is recomputed locally afterwards.
            $table->unsignedInteger('percent_off')->nullable();
            $table->unsignedBigInteger('amount_off')->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamp('applied_at');
            $table->timestamps();

            $table->unique(['stripe_subscription_id', 'stripe_promotion_code_id'], 'applied_promotions_unique');
            $table->index(['tenant_id', 'applied_at']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onUpdate('cascade')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applied_promotions');
    }
};

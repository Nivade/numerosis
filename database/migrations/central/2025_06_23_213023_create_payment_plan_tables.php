<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The plan catalogue: a plan, the features that exist, and which of them each
 * plan offers.
 *
 * Prices are integers in the currency's minor unit. `metadata` is the
 * host-editable JSON `Plan::metadata()` reads; `payment_plan_features.available`
 * is what lets a plan list a feature it does not include.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('description');
            $table->string('monthly_id')->nullable();
            $table->string('yearly_id')->nullable();
            $table->integer('trial_days')->default(0);
            $table->boolean('available')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->integer('monthly_price');
            $table->integer('yearly_price');
            $table->json('metadata')->nullable();
        });

        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->string('description');
            $table->timestamps();
        });

        Schema::create('payment_plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_plan_id')->constrained('payment_plans')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained('features')->cascadeOnDelete();
            $table->boolean('available')->default(true);

            $table->unique(['payment_plan_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_plan_features');
        Schema::dropIfExists('features');
        Schema::dropIfExists('payment_plans');
    }
};

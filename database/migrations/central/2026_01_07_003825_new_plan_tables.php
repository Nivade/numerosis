<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('description');
            $table->string('price_monthly')->nullable();
            $table->string('price_yearly')->nullable();
            $table->integer('trial_days')->default(0);
            $table->timestamps();
        });

        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->string('description');
        });

        Schema::create('payment_plan_features', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_plan_id');
            $table->unsignedBigInteger('feature_id');
            $table->foreign('payment_plan_id')->references('id')->on('payment_plans')->onDelete('cascade');
            $table->foreign('feature_id')->references('id')->on('features')->onDelete('cascade');
            $table->boolean('available')->default(true);

            $table->unique(['payment_plan_id', 'feature_id']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_plan_id')->constrained('payment_plans')->onDelete('cascade');
            $table->string('feature_key');
            $table->string('feature_name');
            $table->text('description')->nullable();
            $table->string('value_type')->default('boolean'); // boolean, integer, string
            $table->text('value')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['payment_plan_id', 'feature_key']);
            $table->index(['payment_plan_id', 'is_enabled']);
        });
    }
};

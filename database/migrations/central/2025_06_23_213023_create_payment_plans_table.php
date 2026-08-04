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
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->enum('billing_cycle', [
                'monthly',
                'yearly',
                'weekly',
                'quarterly',
            ])->default('monthly');
            $table->unsignedInteger('trial_days')->default(0);
            $table->json('features')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_storage_gb')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'slug']);
        });
    }
};

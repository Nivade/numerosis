<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_plans', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('is_active');
            $table->string('stripe_price_id')->nullable()->after('sort_order');
            $table->string('currency')->default('USD')->after('stripe_price_id');
            $table->decimal('setup_fee', 10, 2)->default(0)->after('currency');
            $table->boolean('is_popular')->default(false)->after('setup_fee');
            $table->json('metadata')->nullable()->after('is_popular');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_plans', function (Blueprint $table) {
            $table->dropColumn([
                'sort_order',
                'stripe_price_id',
                'currency',
                'setup_fee',
                'is_popular',
                'metadata',
            ]);
        });
    }
};

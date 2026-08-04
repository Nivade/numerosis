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
            $table->string('monthly_stripe_id')->nullable()->after('stripe_price_id');
            $table->string('yearly_stripe_id')->nullable()->after('monthly_stripe_id');
            $table->decimal('monthly_price', 10, 2)->nullable()->after('price');
            $table->decimal('yearly_price', 10, 2)->nullable()->after('monthly_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_plans', function (Blueprint $table) {
            $table->dropColumn([
                'monthly_stripe_id',
                'yearly_stripe_id',
                'monthly_price',
                'yearly_price',
            ]);
        });
    }
};

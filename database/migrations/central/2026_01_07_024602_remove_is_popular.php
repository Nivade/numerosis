<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_plans', function (Blueprint $table) {
            $table->dropColumn('is_popular');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_plan_id')->nullable();

            $table->foreign('payment_plan_id')->references('id')->on('payment_plans');

        });
    }
};

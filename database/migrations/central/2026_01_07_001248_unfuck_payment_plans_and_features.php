<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('payment_plan_features');
        Schema::dropIfExists('features');
        Schema::whenTableHasColumn('subscriptions', 'payment_plan_id', function (Blueprint $table) {
            if (Schema::hasForeignKey('subscriptions', ['payment_plan_id'])) {
                $table->dropForeign(['payment_plan_id']);
            }
            $table->dropColumn('payment_plan_id');
        });
        Schema::dropIfExists('payment_plans');
    }
};

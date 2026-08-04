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
            Schema::whenTableHasIndex('subscriptions', 'subscriptions_payment_plan_id_foreign', function (Blueprint $table) {
                $table->dropForeign('subscriptions_payment_plan_id_foreign');
            });
            Schema::dropColumns('subscriptions', ['payment_plan_id']);
        });
        Schema::dropIfExists('payment_plans');
    }
};

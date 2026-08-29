<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_plan_features', function (Blueprint $table) {
            $table->dropIndex('payment_plan_features_payment_plan_id_feature_key_index');
            $table->dropColumn(['feature_key', 'feature_name', 'description', 'value_type']);
        });

        Schema::table('payment_plan_features', function (Blueprint $table) {
            // Add foreign key to features table
            $table->foreignId('feature_id')->after('payment_plan_id')->constrained()->onDelete('cascade');

            // Keep plan-specific customizations
            $table->string('value')->nullable()->change();
            $table->json('metadata')->nullable()->after('value');

            // Add unique constraint for payment_plan_id + feature_id
            $table->unique(['payment_plan_id', 'feature_id']);
        });
    }
};

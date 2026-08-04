<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->string('stripe_subscription_item_id')->nullable()->after('purchased_at');
            $table->string('billing_cycle')->nullable()->after('stripe_subscription_item_id');
            $table->timestamp('migrated_at')->nullable()->after('billing_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn(['stripe_subscription_item_id', 'billing_cycle', 'migrated_at']);
        });
    }
};

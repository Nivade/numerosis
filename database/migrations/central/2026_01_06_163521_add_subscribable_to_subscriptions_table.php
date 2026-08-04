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
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('subscribable_id')->nullable()->after('user_id');
            $table->string('subscribable_type')->nullable()->after('subscribable_id');
            $table->index(['subscribable_id', 'subscribable_type']);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['subscribable_id', 'subscribable_type']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The code the customer typed, not the Stripe promotion code id: the id is
 * resolved again when the subscription is created, because a redemption limit
 * can be reached between the two moments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_provisions', function (Blueprint $table): void {
            $table->string('promotion_code')->nullable()->after('billing_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_provisions', function (Blueprint $table): void {
            $table->dropColumn('promotion_code');
        });
    }
};

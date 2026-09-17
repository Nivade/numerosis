<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What of a counter has already reached Stripe. `reported_value` is cumulative
 * rather than a delta, because the identifier sent with a meter event derives
 * from it and Stripe deduplicates on that identifier: a retry recomputes the
 * same number and therefore the same identifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_usage', function (Blueprint $table): void {
            $table->unsignedBigInteger('reported_value')->default(0)->after('value');
            $table->string('report_identifier')->nullable()->after('reported_value');
            $table->timestamp('reported_at')->nullable()->after('report_identifier');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_usage', function (Blueprint $table): void {
            $table->dropColumn(['reported_value', 'report_identifier', 'reported_at']);
        });
    }
};

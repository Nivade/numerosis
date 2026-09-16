<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `Tenant::getCustomColumns()` has to name both columns, or they fold into
     * `data` where the enrolment gate cannot read them.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('requires_two_factor')->default(false);
            $table->timestamp('requires_two_factor_from')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['requires_two_factor', 'requires_two_factor_from']);
        });
    }
};

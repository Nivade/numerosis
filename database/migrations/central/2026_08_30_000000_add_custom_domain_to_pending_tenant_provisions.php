<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Only set under IdentificationMode::CustomDomain. The wizard's `domain`
     * field stays the tenant's safe id/slug; this column carries the
     * fully-qualified domain.
     */
    public function up(): void
    {
        Schema::table('pending_tenant_provisions', function (Blueprint $table) {
            $table->string('custom_domain')->nullable()->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('pending_tenant_provisions', function (Blueprint $table) {
            $table->dropColumn('custom_domain');
        });
    }
};

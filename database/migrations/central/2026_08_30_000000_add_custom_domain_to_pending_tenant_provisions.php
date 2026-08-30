<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Only set under IdentificationMode::CustomDomain, where the wizard's
     * `domain` field stays the tenant's safe id/slug and this column carries
     * the actual fully-qualified domain instead. See
     * .claude/rules/identification-modes.md.
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

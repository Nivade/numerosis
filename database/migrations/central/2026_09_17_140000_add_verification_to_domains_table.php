<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvade\Numerosis\Enums\Tenancy\DomainStatus;

/**
 * Ownership proof for a custom domain. `status` defaults to `active` because a
 * subdomain of the platform's own apex needs no proof; the custom-domain path
 * writes `pending` explicitly.
 *
 * The token is per domain and stable across retries, so a customer who pasted
 * it into their zone does not have to paste a new one after a failed check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->string('status')->default(DomainStatus::Active->value)->after('tenant_id');
            $table->string('verification_token')->nullable()->after('status');
            $table->timestamp('last_checked_at')->nullable()->after('verification_token');

            $table->index(['status', 'last_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropIndex(['status', 'last_checked_at']);
            $table->dropColumn([
                'status',
                'verification_token',
                'last_checked_at',
            ]);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `Nvade\Numerosis\Concerns\Billing\Billable::subscriptions()` overrides Cashier's
     * relation to a MorphMany on subscribable_id/subscribable_type, so
     * Cashier's own webhook write (`$user->subscriptions()->updateOrCreate()`)
     * never populates user_id. With the column NOT NULL, any webhook that
     * resolves a billable (i.e. any retry after the tenant's stripe_id is
     * set) fails with a SQL error instead of writing the row. Only our own
     * write path (Nvade\Numerosis\Actions\Billing\Subscriptions\LinkSubscriptionToTenant)
     * populates it, so it has to be optional.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};

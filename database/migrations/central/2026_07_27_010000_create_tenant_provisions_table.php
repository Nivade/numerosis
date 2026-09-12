<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_provisions', function (Blueprint $table) {
            // Becomes tenants.id, and the subdomain label under
            // IdentificationMode::Subdomain. `custom_domain` below is the
            // only column here holding a real domain.
            $table->string('slug')->primary();
            $table->string('custom_domain')->nullable();
            $table->string('name');
            $table->string('global_id')->index();

            $table->string('payment_plan')->nullable();
            $table->string('billing_cycle')->nullable();
            $table->string('stripe_setup_intent_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();

            // Lifecycle only. Payment settlement is `settled_at`, because the
            // conditional update on this column is what serializes concurrent
            // provisioning attempts for one slug.
            $table->string('status')->default('reserved');
            $table->timestamp('settled_at')->nullable();

            // Written by the acquire, and read back with a staleness window so
            // a worker killed mid-chain does not hold the slug forever.
            $table->timestamp('provisioning_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // One entry per step: done, or skipped with the contribution that
            // was missing. A step already recorded done is not run again.
            $table->json('step_records')->nullable();

            // Contributions with no dedicated column, keyed by class name.
            $table->json('contributions')->nullable();

            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_provisions');
    }
};

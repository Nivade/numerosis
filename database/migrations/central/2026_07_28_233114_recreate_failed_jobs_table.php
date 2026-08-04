<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026_01_07_195854_remove_redundant_tables.php dropped this table along with
 * `jobs`/`cache`/`sessions` on the assumption that moving those to Redis made
 * all four redundant. `failed_jobs` is unrelated to the queue *connection*
 * (Redis) — it's where `QUEUE_FAILED_DRIVER` (default `database-uuids`, never
 * overridden in .env) persists jobs that exhaust their retries. Without it,
 * `WorkCommand::logFailedJob()` throws a QueryException against a table that
 * doesn't exist, uncaught, from inside `queue:work`'s own JobFailed listener.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
    }
};

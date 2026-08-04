<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('', function (Blueprint $table) {
            Schema::dropIfExists('cache');
            Schema::dropIfExists('cache_locks');
            Schema::dropIfExists('sessions');
            Schema::dropIfExists('failed_jobs');
            Schema::dropIfExists('jobs');
            Schema::dropIfExists('job_batches');
        });
    }
};

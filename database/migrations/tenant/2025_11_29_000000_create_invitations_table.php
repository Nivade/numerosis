<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invited_by')->constrained('users')->onDelete('cascade');
            $table->string('tenant_id')->nullable();
            $table->string('email');
            $table->string('role')->default('member');
            $table->string('token')->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a person agreed to, which version of it, and from where. Without this
 * row the lawful basis for holding their data cannot be shown; rows are
 * therefore append-only and survive anonymization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table): void {
            $table->id();
            $table->string('global_user_id');
            $table->string('purpose');
            $table->string('terms_version')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('granted_at');
            $table->timestamps();

            $table->index(['global_user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database counterpart of the central one-time-passwords table.
 * Spatie's `HasOneTimePasswords` writes through whichever connection is
 * default, which is `tenant` for a whole tenant request, so without this every
 * OTP issued to a `Tenant\User` throws "Base table or view not found".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('one_time_passwords', function (Blueprint $table) {
            $table->id();

            $table->string('password');
            $table->text('origin_properties')->nullable();

            $table->dateTime('expires_at');
            $table->morphs('authenticatable');

            $table->timestamps();
        });
    }
};

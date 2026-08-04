<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database counterpart of the central
 * database/migrations/2025_11_25_165156_create_one_time_passwords_table.php.
 * Spatie's HasOneTimePasswords trait writes through whichever connection is
 * currently default, which is 'tenant' for the whole duration of a tenant
 * subdomain request — so without this table, every OTP sent to a
 * Tenant\User (the tenant panel's only login method) throws
 * "Base table or view not found" the moment a code is issued.
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

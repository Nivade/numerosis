<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('zip', 20)->nullable();
            $table->string('company')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            // Add indexes for better performance
            $table->index('email');
            $table->index('is_active');
            $table->index(['first_name', 'last_name']);
            $table->index('company');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'email',
                'phone',
                'address_line_1',
                'address_line_2',
                'city',
                'zip',
                'company',
                'notes',
                'is_active',
            ]);
        });
    }
};

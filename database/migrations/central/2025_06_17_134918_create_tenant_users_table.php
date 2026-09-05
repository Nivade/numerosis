<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'tenant_users',
            function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id');
                $table->string('global_user_id');
                $table->string('invited_by')->nullable()->comment('Global user id of the inviter');
                $table->enum('role', ['owner', 'admin', 'member', 'viewer'])->default('member');
                $table->timestamp('invited_at')->nullable();
                $table->timestamp('joined_at')->nullable();

                $table->unique(['tenant_id', 'global_user_id']);

                $table->foreign('invited_by')
                    ->references('global_id')
                    ->on('users')
                    ->onDelete('set null');

                $table->foreign('tenant_id')
                    ->references('id')
                    ->on('tenants')
                    ->onUpdate('cascade')
                    ->onDelete('cascade');

                $table->foreign('global_user_id')
                    ->references('global_id')
                    ->on('users')
                    ->onUpdate('cascade')
                    ->onDelete('cascade');
                $table->timestamps();
            }
        );
    }
};

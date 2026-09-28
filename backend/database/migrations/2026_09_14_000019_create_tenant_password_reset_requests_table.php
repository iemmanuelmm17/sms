<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_password_reset_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_admin_id')->constrained('tenant_admins')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('domain', 190);
            $table->string('username', 60);
            $table->string('token_hash', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('ip_address', 60)->nullable();
            $table->timestamps();
            $table->index('token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_password_reset_requests');
    }
};

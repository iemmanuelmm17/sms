<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('username', 60);
            $table->string('first_name', 60);
            $table->string('last_name', 60);
            $table->string('password_hash', 255);
            $table->string('secret_question', 200);
            $table->string('secret_answer_hash', 255);
            $table->string('status', 20)->default('active'); // active|deactivated
            $table->unsignedInteger('session_version')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_admins');
    }
};

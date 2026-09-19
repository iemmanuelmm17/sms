<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 1 roles: agent credentials on agents, login attempts, reset requests, audit log. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $t) {
            $t->string('username', 60)->nullable();
            $t->string('password_hash')->nullable();
            $t->string('secret_question', 200)->nullable();
            $t->string('secret_answer_hash')->nullable();
            $t->string('status', 20)->default('active');
            $t->string('default_number', 30)->nullable();
            $t->unsignedInteger('session_version')->default(1);
            $t->timestamp('last_seen_at')->nullable();
            $t->unique(['domain', 'username']);
        });
        Schema::create('login_attempts', function (Blueprint $t) {
            $t->id();
            $t->string('username', 160);
            $t->string('ip_address', 60)->nullable();
            $t->timestamp('attempted_at');
            $t->boolean('success')->default(false);
            $t->index(['username', 'attempted_at']);
            $t->index(['ip_address', 'attempted_at']);
        });
        Schema::create('password_reset_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->string('domain', 160)->nullable();
            $t->string('username', 160)->nullable();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->string('ip_address', 60)->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('domain', 160)->nullable();
            $t->string('actor_type', 20); // admin | agent | system | unknown
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 160)->nullable();
            $t->string('action', 80);
            $t->json('detail')->nullable();
            $t->string('ip_address', 60)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['domain', 'action']);
            $t->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $t) {
            $t->dropUnique(['domain', 'username']);
            $t->dropColumn(['username', 'password_hash', 'secret_question', 'secret_answer_hash',
                'status', 'default_number', 'session_version', 'last_seen_at']);
        });
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('password_reset_requests');
        Schema::dropIfExists('audit_logs');
    }
};

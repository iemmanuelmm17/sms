<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retired password hashes — the last 5 per user.
     *
     * user_type is 'agent' or 'admin', matching the two local-credential
     * tables. Rows are appended by PasswordPolicyService::change() and pruned
     * to 5 in the same call, so this table never grows unbounded.
     */
    public function up(): void
    {
        Schema::create('password_histories', function (Blueprint $table) {
            $table->id();
            $table->string('user_type', 20);              // agent | admin
            $table->unsignedBigInteger('user_id');
            $table->string('password_hash', 255);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_type', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_histories');
    }
};

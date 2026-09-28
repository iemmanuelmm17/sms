<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Third-party integrations, one row per (scope, provider).
        // Rev.io is the first provider; the table is shaped for more.
        // Password is stored encrypted (model cast) and is never
        // serialized to any API response.
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 190)->index();
            $table->string('user', 190)->index();
            $table->string('provider', 30)->index();
            $table->string('username', 190)->default('');
            $table->text('password')->nullable();
            $table->string('client_code', 190)->default('');
            $table->string('status', 20)->default('unconfigured'); // unconfigured|connected|error
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['domain', 'user', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};

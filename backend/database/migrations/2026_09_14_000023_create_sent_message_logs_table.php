<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per SUCCESSFUL outbound send, category stamped at send
        // time (new_sms|regular_reply|mass_sms|auto_reply) so Reporting
        // never has to infer it. Append-only history: deliberately NO
        // foreign keys — agent/tenant deletes must never block on logs.
        Schema::create('sent_message_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('domain', 190)->index();
            $table->string('user', 190)->index();
            $table->unsignedBigInteger('agent_id')->nullable()->index();
            $table->string('actor_name', 190)->nullable();
            $table->string('category', 20)->index();
            $table->unsignedBigInteger('scheduled_message_id')->nullable()->index();
            $table->unsignedBigInteger('auto_reply_id')->nullable();
            $table->string('session_id', 120)->nullable();
            $table->string('from_number', 40)->nullable();
            $table->string('to_number', 40)->nullable()->index();
            $table->string('type', 10)->default('sms');
            $table->dateTime('sent_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_message_logs');
    }
};

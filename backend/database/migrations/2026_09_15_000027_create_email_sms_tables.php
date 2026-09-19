<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - email_sms_senders: per-domain authorized email→SMS senders
     *   (email + the SMS numbers it may send from).
     * - email_sms_notifications: SMS-received notification emails, kept
     *   so replies thread back to the right conversation.
     */
    public function up(): void
    {
        Schema::create('email_sms_senders', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 120);
            $table->string('email', 190);
            $table->json('numbers')->nullable();
            $table->timestamps();
            $table->unique(['domain', 'email']);
            $table->index('domain');
        });
        Schema::create('email_sms_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 120);
            $table->string('user', 120)->nullable();
            $table->string('to_email', 190);
            $table->string('remote', 32);
            $table->string('from_number', 32);
            $table->string('session_id', 64)->nullable();
            $table->string('message_id', 255)->nullable();
            $table->timestamps();
            $table->index('domain');
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_sms_notifications');
        Schema::dropIfExists('email_sms_senders');
    }
};

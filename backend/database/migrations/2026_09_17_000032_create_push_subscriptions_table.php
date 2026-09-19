<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Web Push subscriptions (agents + tenant admins, for background alerts). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('tenant_admin_id')->nullable();
            $table->string('domain');
            $table->string('user');
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->text('p256dh');
            $table->text('auth');
            $table->timestamps();
            $table->index(['domain', 'user']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};

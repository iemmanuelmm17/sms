<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tenant outbound webhooks (event callbacks into tenants' own systems). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->string('user');
            $table->string('url', 500);
            $table->text('secret'); // encrypted cast on the model
            $table->json('events')->nullable(); // null/[] = all events
            $table->string('status')->default('active'); // active|disabled
            $table->unsignedInteger('failure_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamps();
            $table->index(['domain', 'user']);
        });
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_webhook_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->json('payload')->nullable();
            $table->integer('status_code')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('tenant_webhooks');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Source IPs allowed to POST Dynalink webhooks (superadmin CRUD).
        // Empty list = everything rejected (fail closed).
        Schema::create('webhook_allowed_ips', function (Blueprint $table) {
            $table->id();
            $table->string('cidr', 60)->unique();
            $table->string('label', 120)->nullable();
            $table->timestamps();
        });
        // Dynalink webhook senders (observed 2026-09-14).
        $now = now();
        DB::table('webhook_allowed_ips')->insert([
            ['cidr' => '64.113.255.88', 'label' => 'Dynalink 1', 'created_at' => $now, 'updated_at' => $now],
            ['cidr' => '64.113.255.89', 'label' => 'Dynalink 2', 'created_at' => $now, 'updated_at' => $now],
            ['cidr' => '76.9.246.233', 'label' => 'Dynalink 3', 'created_at' => $now, 'updated_at' => $now],
            ['cidr' => '76.9.246.228', 'label' => 'Dynalink 4', 'created_at' => $now, 'updated_at' => $now],
        ]);
        // Per-request trace ID (X-Correlation-ID) for webhook diagnostics.
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('correlation_id', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropColumn('correlation_id');
        });
        Schema::dropIfExists('webhook_allowed_ips');
    }
};

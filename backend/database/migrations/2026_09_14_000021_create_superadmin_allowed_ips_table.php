<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extra IPs/CIDRs allowed into the superadmin portal.
        // Localhost (127.0.0.1, ::1) is always allowed in code.
        Schema::create('superadmin_allowed_ips', function (Blueprint $table) {
            $table->id();
            $table->string('cidr', 60)->unique();
            $table->string('label', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('superadmin_allowed_ips');
    }
};

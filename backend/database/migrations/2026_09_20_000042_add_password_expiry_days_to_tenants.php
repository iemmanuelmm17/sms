<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-tenant password expiry window, in days.
     *
     * This is the value applied to a password at the moment it is SET. Users
     * already mid-cycle keep the day count recorded in their own
     * password_expiry_days_applied column, so editing this never shortens or
     * extends a cycle that is already running.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('password_expiry_days')->default(30)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('password_expiry_days');
        });
    }
};

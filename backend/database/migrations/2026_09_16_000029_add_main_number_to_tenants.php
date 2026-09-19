<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant main SMS number: picked from the Dynalink account's
     * assigned numbers at creation, locked afterwards, and required
     * on every agent of the tenant. Nullable so pre-existing and
     * CLI-created tenants keep working (one-time set in the portal).
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('main_number', 32)->nullable()->after('dynalink_pass');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('main_number');
        });
    }
};

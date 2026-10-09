<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user idle sign-out window (hours of no input before the browser
     * signs the user out). 1–8 = hours, 0 = unlimited. Default 1 keeps the
     * behaviour every existing user already has (the old fixed 60 minutes).
     *
     * Added to every table that holds a signable-in person: tenant admins,
     * portal agents (agent_identities) and legacy local agents (agents).
     */
    public function up(): void
    {
        foreach (['tenant_admins', 'agent_identities', 'agents'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedTinyInteger('idle_timeout_hours')->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach (['tenant_admins', 'agent_identities', 'agents'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('idle_timeout_hours');
            });
        }
    }
};

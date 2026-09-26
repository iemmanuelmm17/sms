<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal profile fields pulled from GET /domains/{domain}/users/{ext}.
     *
     * The portal is the system of record; these are a local cache so the UI
     * (and the message signature) can show a real name instead of the bare
     * extension without a provider round trip on every render.
     */
    public function up(): void
    {
        Schema::table('agent_identities', function (Blueprint $t) {
            $t->string('first_name', 120)->nullable()->after('display_name');
            $t->string('last_name', 120)->nullable()->after('first_name');
            $t->string('email', 190)->nullable()->after('last_name');
            $t->string('department', 190)->nullable()->after('email');
            $t->string('site', 190)->nullable()->after('department');
            // When the profile was last pulled — drives the "synced Xm ago" hint.
            $t->timestamp('profile_synced_at')->nullable()->after('site');
        });
    }

    public function down(): void
    {
        Schema::table('agent_identities', function (Blueprint $t) {
            $t->dropColumn(['first_name', 'last_name', 'email', 'department', 'site', 'profile_synced_at']);
        });
    }
};

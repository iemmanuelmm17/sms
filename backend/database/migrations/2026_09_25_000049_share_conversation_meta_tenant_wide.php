<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make conversation metadata tenant-wide instead of per-viewer.
 *
 * The table was keyed (domain, user, session_id) where `user` is the VIEWER —
 * a portal agent's extension, or the tenant's dynalink_user for an admin. Every
 * person therefore wrote to a private copy, so queue status, pinned, important,
 * archived/spam and assignment were all invisible to everyone else. Two agents
 * sharing a number could not see each other's queue at all.
 *
 * Conversations belong to a NUMBER, and numbers are shared within a tenant, so
 * the metadata has to be shared too. `user` is kept as a column (other code
 * still writes it) but is no longer part of the identity: the unique key
 * becomes (domain, session_id).
 *
 * Existing rows are DISCARDED by choice — the split state is contradictory
 * between viewers and merging it would pick arbitrary winners. Everything
 * un-pins / un-archives / un-assigns once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('conversation_meta')) return;

        // Agreed: start clean rather than merge contradictory per-viewer state.
        DB::table('conversation_meta')->delete();

        Schema::table('conversation_meta', function (Blueprint $t) {
            // Names are driver-dependent; drop by column list so SQLite and
            // MySQL both resolve it.
            try { $t->dropUnique(['domain', 'user', 'session_id']); } catch (\Throwable $e) {}
        });

        Schema::table('conversation_meta', function (Blueprint $t) {
            $t->unique(['domain', 'session_id'], 'conversation_meta_domain_session_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('conversation_meta')) return;

        Schema::table('conversation_meta', function (Blueprint $t) {
            try { $t->dropUnique('conversation_meta_domain_session_unique'); } catch (\Throwable $e) {}
        });
        Schema::table('conversation_meta', function (Blueprint $t) {
            $t->unique(['domain', 'user', 'session_id']);
        });
    }
};

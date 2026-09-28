<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs historical report rows, replacing two workarounds.
 *
 * 1. tenant_id
 *    Portal-agent sends were logged with tenant_id = NULL (the lookup matched
 *    on dynalink_user, but a portal agent's `user` is their extension). Every
 *    report filters on tenant, so those sends were invisible.
 *
 * 2. from_number
 *    Stored as whatever the client sent — sometimes formatted "+1 (212)…".
 *    The agent report matches on digits, so formatted rows never matched.
 *    This was being papered over with a nested REPLACE() in the query, which
 *    was both non-portable (it broke on SQLite) and unable to use an index.
 *
 * Normalising once here lets the query stay a plain, indexed whereIn.
 * Idempotent: re-running changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sent_message_logs')) return;

        // --- 1. Backfill tenant_id from the domain ------------------------
        // Done in PHP rather than "UPDATE ... JOIN", which SQLite rejects.
        if (Schema::hasTable('tenants')) {
            $tenants = DB::table('tenants')->pluck('id', 'domain');   // domain => id
            foreach ($tenants as $domain => $id) {
                DB::table('sent_message_logs')
                    ->whereNull('tenant_id')
                    ->where('domain', $domain)
                    ->update(['tenant_id' => $id]);
            }
        }

        // --- 2. Normalise from_number to digits ---------------------------
        // Chunked so a large table does not load into memory at once.
        DB::table('sent_message_logs')
            ->select('id', 'from_number')
            ->whereNotNull('from_number')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $r) {
                    $digits = preg_replace('/\D/', '', (string) $r->from_number);
                    if ($digits !== (string) $r->from_number) {
                        DB::table('sent_message_logs')
                            ->where('id', $r->id)
                            ->update(['from_number' => $digits]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Normalisation is not reversible — the original formatting is gone,
        // and restoring a null tenant would only re-hide the rows.
    }
};

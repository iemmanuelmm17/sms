<?php

use App\Models\SentMessageLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair send-log rows that were written with tenant_id NULL on a domain
 * that HAS a tenant — they are filtered out of every tenant-scoped report
 * (summary, trend, by-sender, by-number), which is why mass SMS appeared
 * to be "missing" from Reporting.
 *
 * How they got orphaned: the scheduler job attributed rows with an exact
 * (domain, dynalink_user) lookup, but scheduled_messages.user stores the
 * CREATOR's identity — for portal agents that is their extension, which
 * never matches. Writes are fixed (SentMessageLog::tenantFor); this
 * backfills the history. Domains with no Tenant row are genuinely legacy
 * and stay NULL on purpose.
 *
 * Second pass: restore the sender name on scheduled rows whose actor_name
 * is empty from the schedule that produced them, so by-sender grouping
 * shows a real name instead of '(unattended)'.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('tenants')->pluck('id', 'domain') as $domain => $tenantId) {
            DB::table('sent_message_logs')
                ->whereNull('tenant_id')
                ->where('domain', $domain)
                ->update(['tenant_id' => $tenantId]);
        }

        $rows = DB::table('sent_message_logs as l')
            ->join('scheduled_messages as s', 's.id', '=', 'l.scheduled_message_id')
            ->whereNotNull('s.created_by_name')
            ->where(function ($q) {
                $q->whereNull('l.actor_name')->orWhere('l.actor_name', '');
            })
            ->select('l.id', 's.created_by_name')
            ->get();
        foreach ($rows as $r) {
            DB::table('sent_message_logs')
                ->where('id', $r->id)
                ->update(['actor_name' => $r->created_by_name]);
        }

        // Cached report aggregates (5-min TTL, epoch-keyed) must not keep
        // serving pre-backfill numbers.
        SentMessageLog::bumpReportEpoch();
    }

    public function down(): void
    {
        // Not reversibly distinguishable from legitimately-legacy rows, and
        // the send log is append-only history. No-op.
    }
};

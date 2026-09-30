<?php

use App\Models\AutoReply;
use App\Models\AutoReplyLog;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;

/**
 * One auto-reply partition per domain.
 *
 * Rules (and their trigger logs) used to be stored under the creating
 * actor's Dynalink user. Portal agents got a silo under their own
 * extension: admins never saw agent rules, agents never saw admin rules,
 * and the webhook — which resolves the event's terminating user — could
 * miss rules stored under the other partition, so replies never fired.
 * The app now reads/writes everything under the tenant's dynalink_user
 * (AutoReplyService::rulePartitionUser); this migration moves existing
 * rows into that partition. Attribution is untouched: created_by still
 * says which agent made a rule and drives per-agent visibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Tenant::query()->get() as $t) {
            $domain = (string) $t->domain;
            $owner  = (string) $t->dynalink_user;
            if ($domain === '' || $owner === '') continue;

            $orphans = AutoReply::where('domain', $domain)->where('user', '!=', $owner)->get();
            foreach ($orphans as $r) {
                if ($r->is_default) {
                    // The tenant partition owns the two compliance actions
                    // (ensureDefaults recreates them on visit) — drop the
                    // per-extension copies instead of duplicating them.
                    $twin = AutoReply::where('domain', $domain)->where('user', $owner)
                        ->where('default_key', $r->default_key)->exists();
                    if ($twin) { $r->delete(); continue; }
                }
                $r->user = $owner;
                $r->save();
            }

            // Trigger logs follow their rules so the per-domain log feed
            // (GET /api/auto-reply-logs) keeps showing the full history.
            AutoReplyLog::where('domain', $domain)->where('user', '!=', $owner)
                ->update(['user' => $owner]);
        }
    }

    public function down(): void
    {
        // Not reversible: the original per-extension partition values are
        // deliberately discarded (created_by still carries attribution).
    }
};

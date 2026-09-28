<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Password-expiry bookkeeping on both local-credential tables.
     *
     *   password_last_changed_at          — set on every password write
     *   password_expires_at               — last_changed + tenant window
     *   password_expiry_days_applied      — the window THIS cycle used
     *   password_expiry_notice_dismissed_for
     *       — the exact expires_at a "Don't notify again" refers to, so a
     *         new cycle invalidates the old dismissal with no cleanup step
     *
     * Backfill: every existing account that already has a password starts a
     * fresh cycle from now(), so nothing is instantly expired at deploy time.
     * Accounts without a password_hash are left NULL (never expires until a
     * password is first set).
     */
    public function up(): void
    {
        foreach (['agents', 'tenant_admins'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('password_last_changed_at')->nullable()->after('password_hash');
                $t->timestamp('password_expires_at')->nullable()->after('password_last_changed_at');
                $t->timestamp('password_expiry_notice_dismissed_for')->nullable()->after('password_expires_at');
                $t->unsignedInteger('password_expiry_days_applied')->nullable()->after('password_expiry_notice_dismissed_for');
            });
        }

        $this->backfill('agents');
        $this->backfill('tenant_admins');
    }

    public function down(): void
    {
        foreach (['agents', 'tenant_admins'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn([
                    'password_last_changed_at',
                    'password_expires_at',
                    'password_expiry_notice_dismissed_for',
                    'password_expiry_days_applied',
                ]);
            });
        }
    }

    /**
     * Give every account that already has a password a fresh cycle starting
     * now(), using its tenant's current window (default 30 days).
     */
    protected function backfill(string $table): void
    {
        $now = now();

        $rows = DB::table($table)->whereNotNull('password_hash')->get();

        foreach ($rows as $row) {
            $days = $this->tenantDays($table, $row) ?: 30;

            DB::table($table)->where('id', $row->id)->update([
                'password_last_changed_at'    => $now,
                'password_expires_at'         => (clone $now)->addDays($days),
                'password_expiry_days_applied' => $days,
            ]);
        }
    }

    /** Tenant window for this row: agents by (domain, user), admins by tenant_id. */
    protected function tenantDays(string $table, object $row): ?int
    {
        if ($table === 'tenant_admins') {
            $days = DB::table('tenants')->where('id', $row->tenant_id ?? null)->value('password_expiry_days');
            return $days === null ? null : (int) $days;
        }

        $days = DB::table('tenants')
            ->where('domain', $row->domain ?? '')
            ->where('dynalink_user', $row->user ?? '')
            ->value('password_expiry_days');

        return $days === null ? null : (int) $days;
    }
};

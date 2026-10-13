<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Date/time mode becomes a LIST of ranges, and a rule uses either its
     * day/time schedule or its date ranges, never both.
     *
     * Replaces the single active_starts_at / active_ends_at pair from
     * 000055 with date_ranges: [{"from":"YYYY-MM-DD HH:MM","to":…}, …].
     *
     * Existing data: a rule that had a single range keeps it as a one-item
     * list. If a rule had BOTH a day/time schedule and a range (only possible
     * before this change), the day/time schedule is kept and the range is dropped.
     */
    public function up(): void
    {
        Schema::table('auto_replies', function (Blueprint $table) {
            $table->json('date_ranges')->nullable()->after('schedule');
        });

        $rows = DB::table('auto_replies')
            ->where(fn ($q) => $q->whereNotNull('active_starts_at')->orWhereNotNull('active_ends_at'))
            ->get(['id', 'schedule', 'active_starts_at', 'active_ends_at']);

        foreach ($rows as $row) {
            if ($row->schedule !== null) continue; // day/time wins over a leftover range
            DB::table('auto_replies')->where('id', $row->id)->update([
                'date_ranges' => json_encode([[
                    'from' => $row->active_starts_at ?: null,
                    'to'   => $row->active_ends_at ?: null,
                ]]),
            ]);
        }

        Schema::table('auto_replies', function (Blueprint $table) {
            $table->dropColumn(['active_starts_at', 'active_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::table('auto_replies', function (Blueprint $table) {
            $table->string('active_starts_at', 16)->nullable()->after('schedule');
            $table->string('active_ends_at', 16)->nullable()->after('active_starts_at');
        });

        // The old schema only holds one range: restore the first one.
        $rows = DB::table('auto_replies')->whereNotNull('date_ranges')->get(['id', 'date_ranges']);
        foreach ($rows as $row) {
            $list = json_decode((string) $row->date_ranges, true);
            $first = is_array($list) ? ($list[0] ?? null) : null;
            if (!is_array($first)) continue;
            DB::table('auto_replies')->where('id', $row->id)->update([
                'active_starts_at' => $first['from'] ?? null,
                'active_ends_at'   => $first['to'] ?? null,
            ]);
        }

        Schema::table('auto_replies', function (Blueprint $table) {
            $table->dropColumn('date_ranges');
        });
    }
};

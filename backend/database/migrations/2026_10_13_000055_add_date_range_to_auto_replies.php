<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional one-off date/time range for a rule, layered on top of the
     * day/time schedule (both must match). Stored as wall-clock
     * 'YYYY-MM-DD HH:MM' in the rule's timezone, so string comparison is
     * chronological. Start is inclusive, end exclusive. Either bound may be
     * null (open-ended); both null = no date range.
     */
    public function up(): void
    {
        Schema::table('auto_replies', function (Blueprint $table) {
            $table->string('active_starts_at', 16)->nullable()->after('schedule');
            $table->string('active_ends_at', 16)->nullable()->after('active_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('auto_replies', function (Blueprint $table) {
            $table->dropColumn(['active_starts_at', 'active_ends_at']);
        });
    }
};

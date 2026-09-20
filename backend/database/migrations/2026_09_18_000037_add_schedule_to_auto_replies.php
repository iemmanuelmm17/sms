<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-day active windows: {"0":{"from":"09:00","to":"17:00"},"3":{...}}
        // keyed 0=Sunday … 6=Saturday. A day that is absent (or null) is a
        // day the rule stays silent on. Null column = legacy single
        // active_from/active_to window (still honoured).
        Schema::table('auto_replies', function (Blueprint $table) {
            $table->json('schedule')->nullable()->after('active_days');
        });
    }

    public function down(): void
    {
        Schema::table('auto_replies', function (Blueprint $table) {
            $table->dropColumn('schedule');
        });
    }
};

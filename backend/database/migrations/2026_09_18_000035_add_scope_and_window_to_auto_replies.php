<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-rule scope: which SMS numbers the rule is active on (['*'] = all),
        // plus an optional per-rule active window (days + local time range).
        Schema::table('auto_replies', function (Blueprint $table) {
            $table->json('numbers')->nullable()->after('from_number');
            $table->string('active_from', 5)->nullable()->after('numbers');
            $table->string('active_to', 5)->nullable()->after('active_from');
            $table->json('active_days')->nullable()->after('active_to');
            $table->string('timezone', 64)->nullable()->after('active_days');
        });
    }

    public function down(): void
    {
        Schema::table('auto_replies', function (Blueprint $table) {
            $table->dropColumn(['numbers', 'active_from', 'active_to', 'active_days', 'timezone']);
        });
    }
};

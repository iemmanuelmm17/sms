<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recurring sends. The queue worker drives them: when an occurrence
        // finishes, SendScheduledMessage clones it at the next slot — no cron.
        Schema::table('scheduled_messages', function (Blueprint $table) {
            $table->string('recurrence', 16)->nullable()->after('include_optin'); // daily|weekly|monthly
            $table->unsignedInteger('recur_interval')->default(1)->after('recurrence');
            $table->timestamp('recur_until')->nullable()->after('recur_interval');
            $table->unsignedInteger('recur_occurrences')->nullable()->after('recur_until');
            $table->unsignedBigInteger('parent_id')->nullable()->after('recur_occurrences');
            $table->unsignedInteger('recur_index')->default(0)->after('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table) {
            $table->dropColumn(['recurrence', 'recur_interval', 'recur_until', 'recur_occurrences', 'parent_id', 'recur_index']);
        });
    }
};

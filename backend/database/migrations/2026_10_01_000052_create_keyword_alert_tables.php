<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keyword Alerts: an admin-only watchlist — like the auto-responder, but a
 * matched keyword only NOTIFYING, never replying.
 *
 * keyword_alerts     — the rules (keywords, match mode, direction, number scope)
 * keyword_alert_logs — one row per caught message per rule; the alert feed
 *                       renders these and clicking one shows the snapshot
 *                       (full text, numbers, direction) plus a deep link
 *                       into the conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('keyword_alerts')) {
            Schema::create('keyword_alerts', function (Blueprint $t) {
                $t->id();
                $t->string('domain', 190);
                $t->string('user', 190);                        // tenant partition (same as auto-replies)
                $t->string('name', 120);
                $t->text('keywords');                           // JSON array
                $t->string('match_mode', 10)->default('any');   // any | all | exact (same semantics as auto-replies)
                $t->string('direction', 6)->default('both');    // in (received) | out (sent) | both
                $t->text('numbers')->nullable();                // JSON array of digits; null/[] = every tenant number
                $t->boolean('active')->default(true);
                $t->unsignedInteger('trigger_count')->default(0);
                $t->timestamp('last_triggered_at')->nullable();
                $t->string('created_by', 190)->nullable();
                $t->string('created_by_name', 190)->nullable();
                $t->string('updated_by', 190)->nullable();
                $t->string('updated_by_name', 190)->nullable();
                $t->timestamps();
                $t->index(['domain', 'user', 'active']);
            });
        }

        if (!Schema::hasTable('keyword_alert_logs')) {
            Schema::create('keyword_alert_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('keyword_alert_id')->nullable();
                $t->string('domain', 190);
                $t->string('user', 190);
                $t->string('rule_name', 120)->nullable();       // snapshot: the rule may be renamed/deleted later
                $t->string('direction', 6);                     // in | out
                $t->string('matched_keyword', 190)->nullable();
                $t->string('from_number', 32)->nullable();
                $t->string('to_number', 32)->nullable();
                $t->string('sms_number', 32)->nullable();       // the tenant line that caught it
                $t->text('message_text')->nullable();
                $t->string('messagesession_id', 190)->nullable();
                $t->timestamp('occurred_at')->nullable();
                $t->timestamp('read_at')->nullable();
                $t->timestamps();
                $t->index(['keyword_alert_id']);
                $t->index(['domain', 'user', 'read_at']);
                $t->index(['domain', 'user', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_alert_logs');
        Schema::dropIfExists('keyword_alerts');
    }
};

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One caught message per rule: the Keyword Alerts feed renders these rows
 * and clicking one shows the stored snapshot (full text, from/to, tenant
 * line, direction) with a deep link into the conversation.
 *
 * rule_name is a SNAPSHOT on purpose — the feed must stay readable after
 * the rule is renamed or deleted.
 */
class KeywordAlertLog extends Model
{
    protected $fillable = [
        'keyword_alert_id', 'domain', 'user', 'rule_name', 'direction',
        'matched_keyword', 'from_number', 'to_number', 'sms_number',
        'message_text', 'messagesession_id', 'occurred_at', 'read_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'read_at'     => 'datetime',
    ];

    public function rule()
    {
        return $this->belongsTo(KeywordAlert::class, 'keyword_alert_id');
    }
}

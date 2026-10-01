<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A keyword-watch rule: when a message containing one of the keywords
 * arrives on (or leaves) the tenant's numbers, admins get notified — the
 * system NEVER replies (that is AutoReply's job).
 *
 * Rules live in the same single tenant-wide partition as auto-replies
 * (AutoReplyService::rulePartitionUser), so the webhook — which resolves
 * its own user from the event — always finds them.
 */
class KeywordAlert extends Model
{
    public const DIR_IN = 'in';
    public const DIR_OUT = 'out';
    public const DIR_BOTH = 'both';

    protected $fillable = [
        'domain', 'user', 'name', 'keywords', 'match_mode', 'direction',
        'numbers', 'active', 'trigger_count', 'last_triggered_at',
        'created_by', 'created_by_name', 'updated_by', 'updated_by_name',
    ];

    protected $casts = [
        'keywords'          => 'array',
        'numbers'           => 'array',
        'active'            => 'boolean',
        'last_triggered_at' => 'datetime',
    ];

    /**
     * Duck-types AutoReply so AutoReplyService::findMatches() can match
     * these rules verbatim (it reads keywords/match_mode/isCatchAll()).
     * Alerts are always keyword-driven — there is no catch-all.
     */
    public function isCatchAll(): bool
    {
        return false;
    }

    public function logs()
    {
        return $this->hasMany(KeywordAlertLog::class);
    }
}

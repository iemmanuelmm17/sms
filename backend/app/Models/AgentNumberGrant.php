<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Permission for one extension to use one SHARED number.
 *
 * Necessary but not sufficient: AgentAccess also requires the number to still
 * carry the shared flag, so un-sharing revokes access without deleting grants.
 *
 * The row's existence is the VIEW grant (the agent sees this inbox). The
 * two booleans are opt-in actions on top of it:
 *   reply  — may reply to conversations on this number (sent from this number)
 *   create — may start NEW conversations from this number
 */
class AgentNumberGrant extends Model
{
    protected $fillable = ['domain', 'ext', 'number', 'granted_by', 'reply', 'create'];

    protected $casts = [
        'reply'  => 'boolean',
        'create' => 'boolean',
    ];

    /** Numbers are always stored and compared as bare digits. */
    public static function normalizeNumber(?string $n): string
    {
        return preg_replace('/\D/', '', (string) $n) ?? '';
    }
}

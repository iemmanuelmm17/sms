<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Permission for one extension to use one SHARED number.
 *
 * Necessary but not sufficient: AgentAccess also requires the number to still
 * carry the shared flag, so un-sharing revokes access without deleting grants.
 */
class AgentNumberGrant extends Model
{
    protected $fillable = ['domain', 'ext', 'number', 'granted_by'];

    /** Numbers are always stored and compared as bare digits. */
    public static function normalizeNumber(?string $n): string
    {
        return preg_replace('/\D/', '', (string) $n) ?? '';
    }
}

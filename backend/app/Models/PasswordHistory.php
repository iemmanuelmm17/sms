<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per retired password, newest first, pruned to the last
 * PasswordPolicyService::HISTORY entries per user.
 *
 * Deliberately polymorphic-by-column rather than a morph relation: the two
 * credential tables (agents, tenant_admins) have no shared parent, and a
 * plain (user_type, user_id) pair keeps the prune query cheap and obvious.
 */
class PasswordHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_type', 'user_id', 'password_hash', 'created_at'];

    protected $hidden = ['password_hash'];

    protected $casts = ['created_at' => 'datetime'];

    public const TYPE_AGENT = 'agent';
    public const TYPE_ADMIN = 'admin';
}

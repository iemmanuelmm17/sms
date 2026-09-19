<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * Tenant admin: local login (username@tenantname), full admin rights within
 * the tenant's scope. Created only by the superadmin. Mirrors the Agent
 * credential pattern (bcrypt + secret Q/A + session_version kick).
 */
class TenantAdmin extends Model
{
    use HasApiTokens;

    protected $fillable = ['tenant_id', 'username', 'first_name', 'last_name',
        'password_hash', 'secret_question', 'secret_answer_hash',
        'status', 'session_version', 'last_seen_at'];

    /** Credentials are write-only: never serialized to any API response. */
    protected $hidden = ['password_hash', 'secret_answer_hash'];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isActive(): bool
    {
        return ($this->status ?: 'active') === 'active';
    }

    public function displayName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }
}

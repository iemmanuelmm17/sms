<?php

namespace App\Models;

use App\Models\Concerns\HasPasswordExpiry;
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
    use HasPasswordExpiry;

    protected $fillable = ['tenant_id', 'username', 'first_name', 'last_name',
        'password_hash', 'secret_question', 'secret_answer_hash',
        'status', 'session_version', 'last_seen_at', 'onboarding',
        'password_last_changed_at', 'password_expires_at',
        'password_expiry_notice_dismissed_for', 'password_expiry_days_applied',
        'idle_timeout_hours'];

    /** Credentials are write-only: never serialized to any API response. */
    protected $hidden = ['password_hash', 'secret_answer_hash'];

    protected $casts = ['last_seen_at' => 'datetime', 'onboarding' => 'array',
        'password_last_changed_at' => 'datetime', 'password_expires_at' => 'datetime',
        'password_expiry_notice_dismissed_for' => 'datetime',
        'password_expiry_days_applied' => 'integer', 'idle_timeout_hours' => 'integer'];

    /** Password-history bucket for PasswordPolicyService. */
    public function passwordUserType(): string
    {
        return PasswordHistory::TYPE_ADMIN;
    }

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

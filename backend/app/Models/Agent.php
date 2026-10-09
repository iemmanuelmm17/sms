<?php

namespace App\Models;

use App\Models\Concerns\HasPasswordExpiry;
use App\Models\PasswordHistory;
use Illuminate\Database\Eloquent\Model;

/** Shared-inbox agent: name + tag color shown on assigned conversations. */
class Agent extends Model
{
    use HasPasswordExpiry;

    protected $fillable = ['domain', 'user', 'first_name', 'last_name', 'tag_color',
        'username', 'password_hash', 'secret_question', 'secret_answer_hash',
        'status', 'default_number', 'allowed_numbers', 'session_version', 'last_seen_at', 'onboarding',
        'password_last_changed_at', 'password_expires_at',
        'password_expiry_notice_dismissed_for', 'password_expiry_days_applied',
        'idle_timeout_hours'];

    /** Credentials are write-only: never serialized to any API response. */
    protected $hidden = ['password_hash', 'secret_answer_hash'];

    protected $casts = ['last_seen_at' => 'datetime', 'allowed_numbers' => 'array', 'onboarding' => 'array',
        'password_last_changed_at' => 'datetime', 'password_expires_at' => 'datetime',
        'password_expiry_notice_dismissed_for' => 'datetime',
        'password_expiry_days_applied' => 'integer', 'idle_timeout_hours' => 'integer'];

    /** Effective send-numbers: default first, then allowed extras (de-duped digits). */
    /** Roster-list cache (index + directory share one version; any write busts both). */
    public static function listKey(string $domain, string $user): string
    {
        try { $v = (int) \Illuminate\Support\Facades\Cache::get("agents:ver:{$domain}:{$user}", 1); }
        catch (\Throwable $e) { $v = 1; }
        return "agents:list:{$domain}:{$user}:v{$v}";
    }

    public static function bustList(string $domain, string $user): void
    {
        try {
            $k = "agents:ver:{$domain}:{$user}";
            \Illuminate\Support\Facades\Cache::forever($k, (int) \Illuminate\Support\Facades\Cache::get($k, 1) + 1);
        } catch (\Throwable $e) {}
    }

    /** Password-history bucket for PasswordPolicyService. */
    public function passwordUserType(): string
    {
        return PasswordHistory::TYPE_AGENT;
    }

    public function assignedNumbers(): array
    {
        $all = array_merge([$this->default_number], (array) ($this->allowed_numbers ?? []));
        $out = [];
        foreach ($all as $n) {
            $d = preg_replace('/\D/', '', (string) $n);
            if ($d !== '' && !in_array($d, $out, true)) $out[] = $d;
        }
        return $out;
    }
}

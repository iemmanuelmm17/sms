<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A portal-authenticated agent.
 *
 * Holds no credential — the Dynalink portal owns authentication entirely.
 * This row carries only what the portal does not know: display name, tag
 * colour, the active/disabled kill switch and onboarding progress.
 */
class AgentIdentity extends Model
{
    protected $fillable = ['domain', 'ext', 'display_name', 'tag_color',
        'status', 'onboarding', 'last_seen_at', 'first_login_at',
        'first_name', 'last_name', 'email', 'department', 'site', 'profile_synced_at',
        'idle_timeout_hours'];

    protected $casts = [
        'onboarding'        => 'array',
        'last_seen_at'      => 'datetime',
        'first_login_at'    => 'datetime',
        'profile_synced_at' => 'datetime',
        'idle_timeout_hours' => 'integer',
    ];

    /**
     * Best available human name, in order of preference:
     * portal first+last → stored display name → bare extension.
     */
    public function displayName(): string
    {
        $full = trim((string) $this->first_name . ' ' . (string) $this->last_name);
        if ($full !== '') return $full;
        $d = trim((string) $this->display_name);
        return $d !== '' ? $d : (string) $this->ext;
    }

    /**
     * Pull this user's profile from the portal and cache it locally.
     * Returns true when something actually changed.
     */
    public function syncProfile(string $token): bool
    {
        $raw = app(\App\Services\DynalinkService::class)->userProfile($token, $this->domain, $this->ext);
        $p = \App\Services\DynalinkService::shapeUser($raw);
        // A failed/empty lookup must not wipe a name we already have.
        if ($p['full_name'] === null && $p['email'] === null) {
            $this->forceFill(['profile_synced_at' => now()])->save();
            return false;
        }
        $before = [$this->first_name, $this->last_name, $this->email, $this->department, $this->site];
        $this->forceFill([
            'first_name'   => $p['first_name'],
            'last_name'    => $p['last_name'],
            'email'        => $p['email'],
            'department'   => $p['department'],
            'site'         => $p['site'],
            'display_name' => $p['full_name'] ?: $this->display_name,
            'profile_synced_at' => now(),
        ])->save();
        return $before !== [$this->first_name, $this->last_name, $this->email, $this->department, $this->site];
    }

    public function isActive(): bool
    {
        return ($this->status ?: 'active') === 'active';
    }

    public function grants()
    {
        return $this->hasMany(AgentNumberGrant::class, 'ext', 'ext')
            ->where('domain', $this->domain);
    }

    /** Normalize an extension the same way everywhere (trim, no formatting). */
    public static function normalizeExt(?string $ext): string
    {
        return trim((string) $ext);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One row per successful outbound send, category stamped at send time.
 * Written by the 6 send sites (single reply/new, bulk, scheduler job,
 * auto-reply service, email-to-SMS poller); read by ReportController. Metadata only — no
 * message bodies (those stay in Dynalink + the Messages view).
 */
class SentMessageLog extends Model
{
    public const NEW_SMS = 'new_sms';
    public const REGULAR_REPLY = 'regular_reply';
    public const MASS_SMS = 'mass_sms';
    public const AUTO_REPLY = 'auto_reply';
    public const EMAIL_SMS = 'email_sms';
    public const CATEGORIES = [self::NEW_SMS, self::REGULAR_REPLY, self::MASS_SMS, self::AUTO_REPLY, self::EMAIL_SMS];

    protected $fillable = [
        'tenant_id', 'domain', 'user', 'agent_id', 'actor_name', 'category',
        'scheduled_message_id', 'auto_reply_id', 'session_id',
        'from_number', 'to_number', 'type', 'sent_at',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    /**
     * Append a send row. Never throws — a logging failure must never
     * break (or fake-fail) an actual outbound send.
     */
    public static function record(array $attrs): void
    {
        try {
            static::create($attrs + ['sent_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('Send log write failed: ' . $e->getMessage());
        }
    }

    /** Tenant id for a (domain, user) scope — null for legacy scopes. */
    public static function tenantIdFor(string $domain, string $user): ?int
    {
        try {
            $id = Cache::remember("tenant:id:{$domain}:{$user}", 3600,
                fn() => Tenant::where('domain', $domain)->where('dynalink_user', $user)->value('id'));
            return $id === null ? null : (int) $id;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Tenant id for an actor-shaped session array: tenant admins carry
     * it directly; agents resolve via their (domain, user) scope so
     * their sends land in their tenant's report; legacy admins → null.
     */
    public static function scopeTenant(array $s): ?int
    {
        if (isset($s['tenant_id'])) return (int) $s['tenant_id'];
        if (($s['role'] ?? null) === 'agent') {
            return static::tenantIdFor($s['domain'], $s['user']);
        }
        return null;
    }
}

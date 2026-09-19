<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Third-party integration credentials, one row per scope + provider.
 * The password uses Laravel's encrypted cast (reversible: it must be
 * sent to the provider on every API call) and is never serialized.
 */
class Integration extends Model
{
    public const PROVIDER_REVIO = 'revio';

    protected $fillable = [
        'domain', 'user', 'provider', 'username', 'password', 'client_code',
        'status', 'last_checked_at', 'last_error', 'spiels', 'settings',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'password' => 'encrypted',
        'last_checked_at' => 'datetime',
        'spiels' => 'array',
        'settings' => 'array',
    ];

    /** Default journal note posted to Rev.io on ticket status checks. */
    public const DEFAULT_REVIO_NOTE = 'Customer requested update through SMS app.';

    public function revioNote(): string
    {
        $note = trim((string) ((is_array($this->settings) ? $this->settings : [])['revio_note'] ?? ''));
        return $note !== '' ? $note : self::DEFAULT_REVIO_NOTE;
    }

    public function revioUserId(): ?int
    {
        $uid = (is_array($this->settings) ? $this->settings : [])['revio_user_id'] ?? null;
        return is_numeric($uid) && (int) $uid >= 1 ? (int) $uid : null;
    }

    public const DEFAULT_CODE_ATTEMPTS = 3;
    public const DEFAULT_CODE_LOCKOUT_MINUTES = 15;

    public function codeMaxAttempts(): int
    {
        $v = (int) ((is_array($this->settings) ? $this->settings : [])['code_max_attempts'] ?? 0);
        return $v >= 1 ? min($v, 10) : self::DEFAULT_CODE_ATTEMPTS;
    }

    public function codeLockoutMinutes(): int
    {
        $v = (int) ((is_array($this->settings) ? $this->settings : [])['code_lockout_minutes'] ?? 0);
        return $v >= 1 ? min($v, 1440) : self::DEFAULT_CODE_LOCKOUT_MINUTES;
    }

    public const DEFAULT_TICKET_GROUP_ID = 124;
    public const DEFAULT_TICKET_TYPE_ID = 223;
    public const DEFAULT_TICKET_STEP_ID = 139;

    /** Required creation IDs: blank/invalid revert to default, 0 passes through. */
    protected function ticketId(string $key, int $default): int
    {
        $v = (is_array($this->settings) ? $this->settings : [])[$key] ?? null;
        return is_numeric($v) ? max(0, (int) $v) : $default;
    }

    public function ticketGroupId(): int
    {
        return $this->ticketId('ticket_group_id', self::DEFAULT_TICKET_GROUP_ID);
    }

    public function ticketTypeId(): int
    {
        return $this->ticketId('ticket_type_id', self::DEFAULT_TICKET_TYPE_ID);
    }

    public function ticketStepId(): int
    {
        return $this->ticketId('ticket_step_id', self::DEFAULT_TICKET_STEP_ID);
    }

    /** Default business hours: Mon-Fri 9-5, weekends closed. */
    public const DEFAULT_HOURS = [
        'sun' => ['open' => false, 'start' => '09:00', 'end' => '17:00'],
        'mon' => ['open' => true, 'start' => '09:00', 'end' => '17:00'],
        'tue' => ['open' => true, 'start' => '09:00', 'end' => '17:00'],
        'wed' => ['open' => true, 'start' => '09:00', 'end' => '17:00'],
        'thu' => ['open' => true, 'start' => '09:00', 'end' => '17:00'],
        'fri' => ['open' => true, 'start' => '09:00', 'end' => '17:00'],
        'sat' => ['open' => false, 'start' => '09:00', 'end' => '17:00'],
    ];

    /** Merged per-day hours: ['sun' => ['open'=>bool,'start'=>HH:MM,'end'=>HH:MM], ...]. */
    public function businessHours(): array
    {
        $saved = (is_array($this->settings) ? $this->settings : [])['hours'] ?? [];
        if (!is_array($saved)) $saved = [];
        $out = [];
        foreach (self::DEFAULT_HOURS as $day => $def) {
            $row = is_array($saved[$day] ?? null) ? $saved[$day] : [];
            $out[$day] = [
                'open' => (bool) ($row['open'] ?? $def['open']),
                'start' => $this->cleanTime($row['start'] ?? $def['start'], $def['start']),
                'end' => $this->cleanTime($row['end'] ?? $def['end'], $def['end']),
            ];
        }
        return $out;
    }

    protected function cleanTime(mixed $v, string $fallback): string
    {
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $fallback;
    }

    public static function forScope(string $domain, string $user, string $provider): ?self
    {
        return static::where('domain', $domain)->where('user', $user)->where('provider', $provider)->first();
    }

    public static function labelFor(string $provider): string
    {
        return match ($provider) {
            self::PROVIDER_REVIO => 'Rev.io',
            default => $provider,
        };
    }

    public function numbers()
    {
        return $this->hasMany(IntegrationNumber::class);
    }

    public function sessions()
    {
        return $this->hasMany(IntegrationSession::class);
    }
}

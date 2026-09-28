<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoReply extends Model
{
    protected $fillable = [
        'domain', 'user', 'name', 'keywords', 'match_mode',
        'message', 'from_number', 'active', 'trigger_count', 'last_triggered_at',
        'is_default', 'is_deletable', 'default_key', 'default_body', 'default_keywords',
        'created_by', 'created_by_name', 'updated_by', 'updated_by_name',
        'numbers', 'active_from', 'active_to', 'active_days', 'timezone', 'schedule',
        'match_type', 'priority',
    ];

    protected $casts = [
        'keywords'          => 'array',
        'active'            => 'boolean',
        'is_default'        => 'boolean',
        'is_deletable'      => 'boolean',
        'default_keywords'  => 'array',
        'numbers'           => 'array',
        'active_days'       => 'array',
        'schedule'          => 'array',
        'priority'          => 'integer',
        'last_triggered_at' => 'datetime',
    ];

    /** Marker for "every SMS number" in the numbers column. */
    public const ALL_NUMBERS = '*';

    /** Catch-all rule: answers ANY inbound message (24/7, no keywords). */
    public const MATCH_ANY = 'any';
    public const MATCH_KEYWORD = 'keyword';

    public function isCatchAll(): bool
    {
        return $this->match_type === self::MATCH_ANY;
    }

    /**
     * Numbers this rule is active on.
     * ['*'] = every number; null = never re-scoped (pre-feature rules keep
     * their old behaviour: defaults everywhere, admin rules on the main line,
     * agent rules on their own non-main numbers).
     */
    public function scopeNumbers(): ?array
    {
        $n = $this->numbers;
        if ($n === null) return null;
        if (!is_array($n)) return null;
        $n = array_values(array_unique(array_filter(array_map(
            fn($v) => preg_replace('/\D/', '', (string) $v), $n
        ), fn($v) => $v !== '' || $v === self::ALL_NUMBERS)));
        return $n === [] ? [self::ALL_NUMBERS] : $n;
    }

    /** True when ANY window is configured (per-day schedule or the legacy pair). */
    public function hasSchedule(): bool
    {
        $s = $this->schedule;
        if (is_array($s)) return $s !== [];
        return trim((string) ($this->active_from ?? '')) !== '' && trim((string) ($this->active_to ?? '')) !== '';
    }

    /**
     * Window for one weekday (0 = Sunday … 6 = Saturday), or null when the
     * rule stays silent that day. Falls back to the legacy single window for
     * rules that were never re-saved with a per-day schedule.
     */
    public function dayWindow(int $day): ?array
    {
        $s = $this->schedule;
        if (is_array($s)) {
            $w = $s[$day] ?? $s[(string) $day] ?? null;
            if (!is_array($w)) return null;
            $from = substr(trim((string) ($w['from'] ?? '')), 0, 5);
            $to   = substr(trim((string) ($w['to'] ?? '')), 0, 5);
            return ($from !== '' && $to !== '') ? ['from' => $from, 'to' => $to] : null;
        }

        // Legacy: one window shared by the selected days (all days when unset).
        $from = trim((string) ($this->active_from ?? ''));
        $to   = trim((string) ($this->active_to ?? ''));
        if ($from === '' || $to === '') return null;
        $days = $this->active_days;
        if (is_array($days) && $days !== [] && !in_array($day, array_map('intval', array_values($days)), true)) {
            return null;
        }
        return ['from' => substr($from, 0, 5), 'to' => substr($to, 0, 5)];
    }

    /**
     * Is now inside the rule's active window? No window configured = 24/7.
     * Per-day windows are honoured individually; windows that cross midnight
     * (22:00 → 06:00) work as expected.
     */
    public function inSchedule(?\DateTimeInterface $at = null): bool
    {
        if (!$this->hasSchedule()) return true; // no windows → always on

        $tz = $this->timezone ?: config('app.timezone', 'UTC');
        try {
            $now = $at
                ? \Illuminate\Support\Carbon::instance($at)->setTimezone($tz)
                : \Illuminate\Support\Carbon::now($tz);
        } catch (\Throwable $e) {
            $now = \Illuminate\Support\Carbon::now($tz);
        }

        $w = $this->dayWindow((int) $now->format('w')); // 0 = Sunday … 6 = Saturday
        if (!$w) return false;                          // this day has no window

        $from = $w['from'];
        $to   = $w['to'];
        if ($from === $to) return true;                 // midnight-to-midnight

        $cur = $now->format('H:i');
        return $from < $to
            ? ($cur >= $from && $cur < $to)   // same-day window
            : ($cur >= $from || $cur < $to);  // overnight window
    }

    public function logs()
    {
        return $this->hasMany(AutoReplyLog::class);
    }
}

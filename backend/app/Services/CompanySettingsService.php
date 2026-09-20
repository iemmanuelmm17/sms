<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

/**
 * Per-domain company settings (JSON file, same pattern as opt-outs):
 *   storage/app/company-settings/{domain}.json
 * Shape: { "company_name": "...", "auto_reply_cooldown_minutes": 5,
 *   "number_email": { "<digits>": { "notify": [...] } },
 *   "number_shared": { "<digits>": true }, "updated_at": iso }
 */
class CompanySettingsService
{
    public const VAR = '$CompanyName';
    public const AGENT_VAR = '$AgentName';
    public const COOLDOWN_DEFAULT = 5; // auto-reply sender cooldown, minutes (0 = off)
    // TCPA quiet hours: no one should be texted before 8am or after 9pm local.
    public const QUIET_START_DEFAULT = '21:00';
    public const QUIET_END_DEFAULT = '08:00';

    /** HH:MM (24h) or the fallback when the stored value is junk. */
    public static function normalizeHhMm(mixed $v, string $fallback): string
    {
        $s = trim((string) $v);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $s, $m)) {
            $h = (int) $m[1]; $mi = (int) $m[2];
            if ($h >= 0 && $h < 24 && $mi >= 0 && $mi < 60) return sprintf('%02d:%02d', $h, $mi);
        }
        return $fallback;
    }

    protected function path(string $domain): string
    {
        return 'company-settings/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $domain) . '.json';
    }

    protected static array $rawMemo = [];

    public static function bust(string $domain): void
    {
        unset(static::$rawMemo[$domain]);
        try { Cache::forget("companysettings:{$domain}"); } catch (\Throwable $e) {}
    }

    protected function raw(string $domain): array
    {
        // Static memo (same request, e.g. resolve()+name()+notify in one send)
        // over a short shared cache (disk file rarely changes; writers bust).
        if (array_key_exists($domain, static::$rawMemo)) return static::$rawMemo[$domain];
        $data = Cache::remember("companysettings:{$domain}", 120, function () use ($domain) {
            return JsonFileStore::read($this->path($domain), []);
        });
        static::$rawMemo[$domain] = $data;
        return $data;
    }

    public function get(string $domain): array
    {
        $data = $this->raw($domain);
        $ne = [];
        foreach ((array) ($data['number_email'] ?? []) as $k => $v) {
            $d = preg_replace('/\D/', '', (string) $k);
            if (strlen($d) < 7 || strlen($d) > 15) continue;
            $list = [];
            foreach ((array) (is_array($v) ? ($v['notify'] ?? []) : []) as $e) {
                $e = trim((string) $e);
                if ($e !== '') $list[] = $e;
            }
            $ne[$d] = ['notify' => array_values(array_unique($list)), 'enabled' => !is_array($v) || ($v['enabled'] ?? true) !== false];
        }
        $ns = [];
        foreach ((array) ($data['number_shared'] ?? []) as $k => $v) {
            $d = preg_replace('/\D/', '', (string) $k);
            if (strlen($d) < 7 || strlen($d) > 15) continue;
            if ($v) $ns[$d] = true;
        }
        $q = is_array($data['quiet_hours'] ?? null) ? $data['quiet_hours'] : [];
        return [
            'company_name' => (string) ($data['company_name'] ?? ''),
            'auto_reply_cooldown_minutes' => (int) ($data['auto_reply_cooldown_minutes'] ?? self::COOLDOWN_DEFAULT),
            'number_email' => $ne,
            'number_shared' => $ns,
            'quiet_hours' => [
                'enabled' => array_key_exists('enabled', $q) ? (bool) $q['enabled'] : true,
                'start'   => self::normalizeHhMm($q['start'] ?? '', self::QUIET_START_DEFAULT),
                'end'     => self::normalizeHhMm($q['end'] ?? '', self::QUIET_END_DEFAULT),
            ],
        ];
    }

    /** Minutes between auto-replies to the same sender (0 = disabled). */
    public function cooldown(string $domain): int
    {
        return max(0, $this->get($domain)['auto_reply_cooldown_minutes']);
    }

    public function name(string $domain): string
    {
        return $this->get($domain)['company_name'];
    }

    public function set(string $domain, string $name, ?int $cooldown = null): array
    {
        $data = JsonFileStore::mutate($this->path($domain), function ($raw) use ($name, $cooldown) {
            return array_merge($raw, [
                'company_name' => trim($name),
                'auto_reply_cooldown_minutes' => $cooldown ?? (int) ($raw['auto_reply_cooldown_minutes'] ?? self::COOLDOWN_DEFAULT),
                'updated_at' => now()->toISOString(),
            ]);
        });
        static::bust($domain);
        return $data;
    }

    /** Configured quiet hours for the domain. */
    public function quietHours(string $domain): array
    {
        return $this->get($domain)['quiet_hours'];
    }

    public function setQuietHours(string $domain, bool $enabled, string $start, string $end): array
    {
        $start = self::normalizeHhMm($start, self::QUIET_START_DEFAULT);
        $end = self::normalizeHhMm($end, self::QUIET_END_DEFAULT);
        $data = JsonFileStore::mutate($this->path($domain), function ($raw) use ($enabled, $start, $end) {
            return array_merge($raw, [
                'quiet_hours' => ['enabled' => $enabled, 'start' => $start, 'end' => $end],
                'updated_at' => now()->toISOString(),
            ]);
        });
        static::bust($domain);
        return $data;
    }

    public function setNumberEmail(string $domain, string $digits, array $notify, bool $enabled = true): array
    {
        $data = JsonFileStore::mutate($this->path($domain), function ($raw) use ($digits, $notify, $enabled) {
            $ne = (array) ($raw['number_email'] ?? []);
            $list = [];
            foreach ($notify as $e) {
                $e = trim((string) $e);
                if ($e !== '') $list[] = $e;
            }
            $list = array_values(array_unique($list));
            if ($list === [] && $enabled) unset($ne[$digits]);
            else $ne[$digits] = ['notify' => $list, 'enabled' => $enabled];
            return array_merge($raw, ['number_email' => $ne, 'updated_at' => now()->toISOString()]);
        });
        static::bust($domain);
        return $data;
    }

    public function setNumberShared(string $domain, string $digits, bool $shared): array
    {
        $data = JsonFileStore::mutate($this->path($domain), function ($raw) use ($digits, $shared) {
            $ns = (array) ($raw['number_shared'] ?? []);
            if ($shared) $ns[$digits] = true;
            else unset($ns[$digits]);
            return array_merge($raw, ['number_shared' => $ns, 'updated_at' => now()->toISOString()]);
        });
        static::bust($domain);
        return $data;
    }

    /** Digits list of numbers flagged shared (agents may all see these threads). */
    public function sharedNumbers(string $domain): array
    {
        return array_keys($this->get($domain)['number_shared']);
    }

    public function isShared(string $domain, string $digits): bool
    {
        return isset($this->get($domain)['number_shared'][$digits]);
    }

    /** Validated notify addresses for one number (may be empty). */
    public function numberNotifyEmails(string $domain, string $digits): array
    {
        $cfg = $this->get($domain)['number_email'][$digits] ?? [];
        if (($cfg['enabled'] ?? true) === false) return [];
        $out = [];
        foreach ((array) ($this->get($domain)['number_email'][$digits]['notify'] ?? []) as $e) {
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = strtolower($e);
        }
        return array_values(array_unique($out));
    }

    /**
     * Distinct IMAP folders to poll: INBOX (always, as the safety net)
     * plus one folder per active tenant (folder = tenant name, which is
     * letters/numbers only). Legacy domains without a tenant row are
     * covered by INBOX.
     */
    public function mailFolderList(): array
    {
        $folders = ['INBOX'];
        try {
            foreach (Tenant::query()->get(['name', 'status']) as $t) {
                if (!$t->isActive()) continue;
                $f = (string) $t->name;
                if ($f !== '' && strcasecmp($f, 'INBOX') !== 0
                    && preg_match('/^[A-Za-z0-9-]{1,60}$/', $f)) {
                    $folders[] = $f;
                }
            }
        } catch (\Throwable $e) {
        }
        return array_values(array_unique($folders));
    }

    /**
     * Resolve $CompanyName (any case) + $AgentName in outbound text.
     * No-op when absent. $agentName is the human sender (''
     * for unattended sends like auto-replies).
     */
    public function resolve(string $domain, string $text, string $agentName = ''): string
    {
        if (!str_contains($text, '$')) return $text;
        $text = str_ireplace(self::VAR, $this->name($domain), $text);
        return str_ireplace(self::AGENT_VAR, $agentName, $text);
    }
}

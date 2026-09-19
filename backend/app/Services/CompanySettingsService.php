<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

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

    protected function path(string $domain): string
    {
        return 'company-settings/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $domain) . '.json';
    }

    protected function raw(string $domain): array
    {
        if (Storage::exists($this->path($domain))) {
            $data = json_decode(Storage::get($this->path($domain)), true);
            if (is_array($data)) return $data;
        }
        return [];
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
        return [
            'company_name' => (string) ($data['company_name'] ?? ''),
            'auto_reply_cooldown_minutes' => (int) ($data['auto_reply_cooldown_minutes'] ?? self::COOLDOWN_DEFAULT),
            'number_email' => $ne,
            'number_shared' => $ns,
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
        $prev = $this->get($domain);
        $data = array_merge($this->raw($domain), [
            'company_name' => trim($name),
            'auto_reply_cooldown_minutes' => $cooldown ?? $prev['auto_reply_cooldown_minutes'],
            'updated_at' => now()->toISOString(),
        ]);
        Storage::put($this->path($domain), json_encode($data, JSON_PRETTY_PRINT));
        return $data;
    }

    public function setNumberEmail(string $domain, string $digits, array $notify, bool $enabled = true): array
    {
        $raw = $this->raw($domain);
        $ne = (array) ($raw['number_email'] ?? []);
        $list = [];
        foreach ($notify as $e) {
            $e = trim((string) $e);
            if ($e !== '') $list[] = $e;
        }
        $list = array_values(array_unique($list));
        if ($list === [] && $enabled) unset($ne[$digits]);
        else $ne[$digits] = ['notify' => $list, 'enabled' => $enabled];
        $data = array_merge($raw, ['number_email' => $ne, 'updated_at' => now()->toISOString()]);
        Storage::put($this->path($domain), json_encode($data, JSON_PRETTY_PRINT));
        return $data;
    }

    public function setNumberShared(string $domain, string $digits, bool $shared): array
    {
        $raw = $this->raw($domain);
        $ns = (array) ($raw['number_shared'] ?? []);
        if ($shared) $ns[$digits] = true;
        else unset($ns[$digits]);
        $data = array_merge($raw, ['number_shared' => $ns, 'updated_at' => now()->toISOString()]);
        Storage::put($this->path($domain), json_encode($data, JSON_PRETTY_PRINT));
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

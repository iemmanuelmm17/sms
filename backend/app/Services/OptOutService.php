<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use App\Models\OptEvent;

/**
 * TCPA do-not-contact list. JSON per domain (same pattern as
 * companies/groups): storage/app/optouts/{domain}.json
 * Shape: { "<digits>": { "at": iso, "source": "stop-keyword|manual", "note"?, "numbers": ["*"]|[digits...] } }
 * numbers ["*"] blocks all senders; otherwise only those business numbers.
 */
class OptOutService
{
    public const STOP_WORDS = ['stop', 'stopall', 'unsubscribe', 'unsubscribed', 'cancel', 'end', 'quit'];
    public const START_WORDS = ['start', 'subscribed', 'yes', 'unstop'];

    public static function digits(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    protected function path(string $domain): string
    {
        return 'optouts/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $domain) . '.json';
    }

    public function all(string $domain): array
    {
        if (!Storage::exists($this->path($domain))) return [];
        $data = json_decode(Storage::get($this->path($domain)), true);
        return is_array($data) ? $data : [];
    }

    protected function save(string $domain, array $list): void
    {
        Storage::put($this->path($domain), json_encode($list, JSON_PRETTY_PRINT));
    }

    /** Find an entry by digits, accepting 10/11-digit US variants. */
    protected function findEntry(array $list, string $d): ?array
    {
        if (isset($list[$d])) return $list[$d];
        if (strlen($d) === 11 && str_starts_with($d, '1') && isset($list[substr($d, 1)])) return $list[substr($d, 1)];
        if (strlen($d) === 10 && isset($list['1' . $d])) return $list['1' . $d];
        return null;
    }

    public function isOptedOut(string $domain, string $phone, ?string $number = null): bool
    {
        $d = self::digits($phone);
        if ($d === '') return false;
        $entry = $this->findEntry($this->all($domain), $d);
        if (!is_array($entry)) return false;
        $nums = array_map('strval', (array) ($entry['numbers'] ?? ['*']));
        if (in_array('*', $nums, true)) return true;
        if ($number === null) return true; // no sender context: any entry counts
        $nd = self::digits($number);
        $cands = [$nd];
        if (strlen($nd) === 11 && str_starts_with($nd, '1')) $cands[] = substr($nd, 1);
        if (strlen($nd) === 10) $cands[] = '1' . $nd;
        foreach ($cands as $c) { if (in_array($c, $nums, true)) return true; }
        return false;
    }

    /** @return bool true if the list changed */
    public function optOut(string $domain, string $phone, string $source = 'manual', ?string $note = null, ?string $number = null): bool
    {
        $d = self::digits($phone);
        if (strlen($d) < 10) return false;
        $list = $this->all($domain);
        $scope = $number ? self::digits($number) : '*';
        // Same contact already listed (either digit variant): merge the scope in.
        foreach ([$d, strlen($d) === 11 && str_starts_with($d, '1') ? substr($d, 1) : null, strlen($d) === 10 ? '1' . $d : null] as $k) {
            if ($k !== null && isset($list[$k])) {
                $nums = array_map('strval', (array) ($list[$k]['numbers'] ?? ['*']));
                if (!in_array('*', $nums, true) && !in_array($scope, $nums, true)) {
                    $list[$k]['numbers'] = $scope === '*' ? ['*'] : array_values([...$nums, $scope]);
                    $this->save($domain, $list);
                    $this->recordEvent($domain, $d, 'opt_out', $note ?: 'manual');
                    \App\Models\TenantWebhook::fire($domain, null, 'optout.added', ['phone' => $d, 'source' => $source, 'number' => $scope]);
                    return true;
                }
                return false;
            }
        }
        $entry = ['at' => now()->toISOString(), 'source' => $source, 'numbers' => [$scope]];
        if ($note) $entry['note'] = $note;
        $list[$d] = $entry;
        $this->save($domain, $list);
        $this->recordEvent($domain, $d, 'opt_out', $note ?: 'manual');
        \App\Models\TenantWebhook::fire($domain, null, 'optout.added', ['phone' => $d, 'source' => $source, 'number' => $scope]);
        return true;
    }

    public function remove(string $domain, string $phone, string $keyword = 'manual'): bool
    {
        $d = self::digits($phone);
        $list = $this->all($domain);
        $keys = [$d];
        if (strlen($d) === 11 && str_starts_with($d, '1')) $keys[] = substr($d, 1);
        if (strlen($d) === 10) $keys[] = '1' . $d;
        $hit = false;
        foreach ($keys as $k) {
            if (isset($list[$k])) { unset($list[$k]); $hit = true; }
        }
        if ($hit) {
            $this->save($domain, $list);
            $this->recordEvent($domain, $d, 'opt_in', $keyword);
            \App\Models\TenantWebhook::fire($domain, null, 'optout.removed', ['phone' => $d, 'keyword' => $keyword]);
        }
        return $hit;
    }

    /** History row for the TCPA tabs. Never breaks enforcement. */
    protected function recordEvent(string $domain, string $digits, string $direction, ?string $keyword): void
    {
        try {
            OptEvent::create([
                'domain' => $domain, 'phone_number' => $digits, 'contact_id' => null,
                'direction' => $direction, 'keyword' => $keyword, 'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    /** Exact keyword match (case-insensitive, edge punctuation ignored). */
    public static function stopWord(?string $text): ?string
    {
        $t = strtolower(trim((string) $text, " \t\n\r\0\x0B!?.\"'"));
        return in_array($t, self::STOP_WORDS, true) ? $t : null;
    }

    public static function startWord(?string $text): ?string
    {
        $t = strtolower(trim((string) $text, " \t\n\r\0\x0B!?.\"'"));
        return in_array($t, self::START_WORDS, true) ? $t : null;
    }
}

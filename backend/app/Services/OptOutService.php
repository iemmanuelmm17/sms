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

    protected static array $memo = [];

    public function all(string $domain): array
    {
        if (array_key_exists($domain, static::$memo)) return static::$memo[$domain];
        return static::$memo[$domain] = JsonFileStore::read($this->path($domain), []);
    }

    protected function save(string $domain, array $list): void
    {
        JsonFileStore::put($this->path($domain), $list);
        unset(static::$memo[$domain]);
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
        $scope = $number ? self::digits($number) : '*';
        $changed = false;
        JsonFileStore::mutate($this->path($domain), function ($list) use ($d, $scope, $source, $note, &$changed) {
            // Same contact already listed (either digit variant): merge the scope in.
            foreach ([$d, strlen($d) === 11 && str_starts_with($d, '1') ? substr($d, 1) : null, strlen($d) === 10 ? '1' . $d : null] as $k) {
                if ($k !== null && isset($list[$k])) {
                    $nums = array_map('strval', (array) ($list[$k]['numbers'] ?? ['*']));
                    if (!in_array('*', $nums, true) && !in_array($scope, $nums, true)) {
                        $list[$k]['numbers'] = $scope === '*' ? ['*'] : array_values([...$nums, $scope]);
                        $changed = true;
                    }
                    return $list;
                }
            }
            $entry = ['at' => now()->toISOString(), 'source' => $source, 'numbers' => [$scope]];
            if ($note) $entry['note'] = $note;
            $list[$d] = $entry;
            $changed = true;
            return $list;
        });
        unset(static::$memo[$domain]);
        if ($changed) {
            $this->recordEvent($domain, $d, 'opt_out', $note ?: 'manual');
            \App\Models\TenantWebhook::fire($domain, null, 'optout.added', ['phone' => $d, 'source' => $source, 'number' => $scope]);
        }
        return $changed;
    }

    public function remove(string $domain, string $phone, string $keyword = 'manual'): bool
    {
        $d = self::digits($phone);
        $keys = [$d];
        if (strlen($d) === 11 && str_starts_with($d, '1')) $keys[] = substr($d, 1);
        if (strlen($d) === 10) $keys[] = '1' . $d;
        $hit = false;
        JsonFileStore::mutate($this->path($domain), function ($list) use ($keys, &$hit) {
            foreach ($keys as $k) {
                if (isset($list[$k])) { unset($list[$k]); $hit = true; }
            }
            return $list;
        });
        unset(static::$memo[$domain]);
        if ($hit) {
            $this->recordEvent($domain, $d, 'opt_in', $keyword);
            \App\Models\TenantWebhook::fire($domain, null, 'optout.removed', ['phone' => $d, 'keyword' => $keyword]);
        }
        return $hit;
    }

    /**
     * Digits whose LATEST TCPA action was an opt-in (START / manual re-add).
     * Accepts 10- and 11-digit variants of the same number.
     *
     * @param  array $phones  candidate phone numbers (any format)
     * @return array          digits (as passed in, de-duped) that are opted in
     */
    public function optedInDigits(string $domain, array $phones): array
    {
        $wanted = [];
        foreach ($phones as $p) {
            $d = self::digits((string) $p);
            if ($d !== '') $wanted[$d] = $d;
        }
        if ($wanted === []) return [];

        // Query both digit variants so 10/11-digit forms resolve to one contact.
        $cands = [];
        foreach ($wanted as $d) {
            $cands[$d] = $d;
            if (strlen($d) === 11 && str_starts_with($d, '1')) $cands[substr($d, 1)] = substr($d, 1);
            if (strlen($d) === 10) $cands['1' . $d] = '1' . $d;
        }

        try {
            $rows = OptEvent::where('domain', $domain)
                ->whereIn('phone_number', array_values($cands))
                ->orderBy('occurred_at')->orderBy('id')->get();
        } catch (\Throwable $e) {
            return [];
        }

        $latest = [];  // digits => last direction seen (ascending order → last wins)
        foreach ($rows as $r) {
            $latest[(string) $r->phone_number] = (string) $r->direction;
        }

        $out = [];
        foreach ($wanted as $d) {
            $state = $latest[$d] ?? null;
            if ($state === null) {
                $alt = strlen($d) === 11 && str_starts_with($d, '1') ? substr($d, 1) : (strlen($d) === 10 ? '1' . $d : null);
                $state = $alt !== null ? ($latest[$alt] ?? null) : null;
            }
            if ($state === 'opt_in') $out[] = $d;
        }
        return $out;
    }

    /** True when this number's latest TCPA action was an opt-in. */
    public function isOptedIn(string $domain, string $phone): bool
    {
        $d = self::digits($phone);
        if ($d === '') return false;
        return in_array($d, $this->optedInDigits($domain, [$d]), true);
    }

    /**
     * Every number whose LATEST TCPA action was an opt-in (START).
     * These are the people a blast can be extended to. Numbers currently on
     * the do-not-contact list are never returned, whatever their history.
     *
     * @return string[] 10-digit numbers, de-duped
     */
    public function optedInNumbers(string $domain): array
    {
        try {
            $rows = OptEvent::where('domain', $domain)
                ->orderBy('occurred_at')->orderBy('id')
                ->get(['phone_number', 'direction']);
        } catch (\Throwable $e) {
            return [];
        }

        $latest = [];  // 10-digit key => last direction seen (ascending → last wins)
        foreach ($rows as $r) {
            $d = self::digits((string) $r->phone_number);
            if ($d === '') continue;
            $key = strlen($d) === 11 && str_starts_with($d, '1') ? substr($d, 1) : $d;
            if (strlen($key) !== 10) continue;
            $latest[$key] = (string) $r->direction;
        }

        $out = [];
        foreach ($latest as $key => $dir) {
            if ($dir !== 'opt_in') continue;
            foreach ([$key, '1' . $key] as $variant) {
                if ($this->isOptedOut($domain, $variant)) continue 2; // DNC always wins
            }
            $out[] = $key;
        }
        return array_values(array_unique($out));
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

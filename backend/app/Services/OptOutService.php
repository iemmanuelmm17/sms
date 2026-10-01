<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Models\OptEvent;

/**
 * TCPA do-not-contact list.
 *
 * Storage: the `opt_outs` database table (migration 000053 imported the
 * legacy JSON). Compliance state now lives with the rest of the data —
 * transactional, backed up with the DB, auditable.
 *
 * Safety rails (this list gates SENDS — it must never silently empty):
 *  - If the table is missing/unreadable (e.g. code deployed before
 *    `php artisan migrate`), every read/write falls back to the legacy
 *    JSON store: storage/app/optouts/{domain}.json.
 *  - Every DB write is mirrored into the JSON file (best effort), so the
 *    on-disk copy stays a current cold backup and a downgrade is safe.
 *
 * Row shape mirrors the old JSON entry:
 *   digits => { at: iso, source: stop-keyword|manual, note?, numbers: ["*"]|[digits...] }
 * numbers ["*"] blocks all senders; otherwise only those business numbers.
 *
 * The per-event audit trail (who opted out/in, when, with which keyword)
 * is the OptEvent table — unchanged.
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

    /** One-shot per process: has the opt_outs migration run? */
    protected static ?bool $dbReady = null;

    protected function dbReady(): bool
    {
        if (static::$dbReady === null) {
            try {
                static::$dbReady = Schema::hasTable('opt_outs');
            } catch (\Throwable $e) {
                static::$dbReady = false;
            }
        }
        return static::$dbReady;
    }

    /** 10/11-digit lookup variants of the same number, exact form first. */
    protected static function variants(string $d): array
    {
        $keys = [$d];
        if (strlen($d) === 11 && str_starts_with($d, '1')) $keys[] = substr($d, 1);
        if (strlen($d) === 10) $keys[] = '1' . $d;
        return $keys;
    }

    public function all(string $domain): array
    {
        if (array_key_exists($domain, static::$memo)) return static::$memo[$domain];
        if ($this->dbReady()) {
            try {
                $rows = DB::table('opt_outs')->where('domain', $domain)->orderBy('id')->get();
                $map = [];
                foreach ($rows as $row) {
                    $nums = json_decode((string) $row->numbers, true);
                    $nums = is_array($nums) && $nums !== [] ? array_map('strval', $nums) : ['*'];
                    try {
                        $at = \Illuminate\Support\Carbon::parse($row->created_at)->toISOString();
                    } catch (\Throwable $e) {
                        $at = now()->toISOString();
                    }
                    $entry = ['at' => $at, 'source' => (string) $row->source, 'numbers' => $nums];
                    if ($row->note !== null && $row->note !== '') $entry['note'] = (string) $row->note;
                    $map[(string) $row->digits] = $entry;
                }
                return static::$memo[$domain] = $map;
            } catch (\Throwable $e) {
                Log::error('optout:db-read-failed — falling back to JSON', ['domain' => $domain, 'error' => (string) $e]);
            }
        }
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
        if ($this->dbReady()) {
            try {
                $changed = DB::transaction(function () use ($domain, $d, $scope, $source, $note) {
                    // Same precedence the JSON store used: exact digits, then
                    // the 10/11-digit variant of the same contact.
                    $row = null;
                    foreach (self::variants($d) as $k) {
                        $row = DB::table('opt_outs')->where('domain', $domain)->where('digits', $k)->first();
                        if ($row) break;
                    }
                    if ($row) {
                        $nums = json_decode((string) $row->numbers, true);
                        $nums = is_array($nums) && $nums !== [] ? array_map('strval', $nums) : ['*'];
                        if (!in_array('*', $nums, true) && !in_array($scope, $nums, true)) {
                            $merged = $scope === '*' ? ['*'] : array_values([...$nums, $scope]);
                            DB::table('opt_outs')->where('id', $row->id)
                                ->update(['numbers' => json_encode($merged), 'updated_at' => now()]);
                            return true;
                        }
                        return false; // already blocked at this scope
                    }
                    DB::table('opt_outs')->insert([
                        'domain' => $domain, 'digits' => $d, 'source' => $source,
                        'note' => $note, 'numbers' => json_encode([$scope]),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    return true;
                });
                // Mirror into the JSON cold backup (best effort — the DB is
                // the source of truth; a mirror failure must not undo it).
                try { $this->optOutJson($domain, $d, $scope, $source, $note); } catch (\Throwable $e) {}
            } catch (\Throwable $e) {
                // A DNC write must never silently vanish: fall back to the
                // legacy JSON store and log loudly.
                Log::error('optout:db-write-failed — falling back to JSON', ['domain' => $domain, 'phone' => $d, 'error' => (string) $e]);
                $changed = $this->optOutJson($domain, $d, $scope, $source, $note);
            }
        } else {
            $changed = $this->optOutJson($domain, $d, $scope, $source, $note);
        }
        unset(static::$memo[$domain]);
        if ($changed) {
            $this->recordEvent($domain, $d, 'opt_out', $note ?: 'manual');
            \App\Models\TenantWebhook::fire($domain, null, 'optout.added', ['phone' => $d, 'source' => $source, 'number' => $scope]);
        }
        return $changed;
    }

    /** Legacy JSON write path — still the fallback + the mirror. */
    protected function optOutJson(string $domain, string $d, string $scope, string $source, ?string $note): bool
    {
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
        return $changed;
    }

    public function remove(string $domain, string $phone, string $keyword = 'manual'): bool
    {
        $d = self::digits($phone);
        $keys = self::variants($d);
        $hit = false;
        if ($this->dbReady()) {
            try {
                $hit = DB::table('opt_outs')->where('domain', $domain)->whereIn('digits', $keys)->delete() > 0;
                // Mirror into the JSON cold backup (best effort).
                try { $this->removeJson($domain, $keys); } catch (\Throwable $e) {}
            } catch (\Throwable $e) {
                Log::error('optout:db-remove-failed — falling back to JSON', ['domain' => $domain, 'phone' => $d, 'error' => (string) $e]);
                $hit = $this->removeJson($domain, $keys);
            }
        } else {
            $hit = $this->removeJson($domain, $keys);
        }
        unset(static::$memo[$domain]);
        if ($hit) {
            $this->recordEvent($domain, $d, 'opt_in', $keyword);
            \App\Models\TenantWebhook::fire($domain, null, 'optout.removed', ['phone' => $d, 'keyword' => $keyword]);
        }
        return $hit;
    }

    /** Legacy JSON remove path — still the fallback + the mirror. */
    protected function removeJson(string $domain, array $keys): bool
    {
        $hit = false;
        JsonFileStore::mutate($this->path($domain), function ($list) use ($keys, &$hit) {
            foreach ($keys as $k) {
                if (isset($list[$k])) { unset($list[$k]); $hit = true; }
            }
            return $list;
        });
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

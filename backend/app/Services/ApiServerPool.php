<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pool of interchangeable NS-API hosts, rotated per call to spread load.
 *
 * Configured by a superadmin (Settings key `dynalink.servers`, newline or
 * comma separated). Empty pool = fall back to the single configured host, so
 * this is inert until someone actually adds servers.
 *
 * Rotation is a shared atomic counter in the cache, not a per-process value —
 * with several PHP workers a per-process counter would leave every worker
 * hammering whichever host it happened to start on.
 *
 * Hosts that fail to connect are parked briefly rather than being retried on
 * every request; a pool where one host is down would otherwise send a
 * predictable fraction of all traffic into a timeout.
 */
class ApiServerPool
{
    /** Cache key holding the round-robin cursor. */
    private const CURSOR = 'apipool:cursor';

    /** How long a host stays parked after a connection failure. */
    private const PENALTY_SECONDS = 60;

    /** Settings key the superadmin writes. */
    public const SETTING = 'dynalink.servers';

    /** Settings key: max calls per second PER HOST (0 = unlimited). */
    public const RATE_SETTING = 'dynalink.rate_per_sec';

    /**
     * How long a caller will wait for a slot before giving up.
     *
     * Almost every provider call here happens inside a user's HTTP request,
     * so an unbounded wait would just convert "provider overloaded" into
     * "app hangs". A short wait smooths over bursts; anything longer should
     * fail fast and tell the user to retry.
     */
    private const MAX_WAIT_MS = 400;

    /**
     * Configured hosts, normalised and de-duplicated.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $raw = (string) (Settings::get(self::SETTING, '') ?? '');
        if (trim($raw) === '') return [];

        $out = [];
        foreach (preg_split('/[\r\n,]+/', $raw) as $line) {
            $u = self::normalize($line);
            if ($u !== '' && !in_array($u, $out, true)) $out[] = $u;
        }
        return $out;
    }

    /** Trim, drop a trailing slash, and require an http(s) scheme. */
    public static function normalize(?string $url): string
    {
        $u = rtrim(trim((string) $url), '/');
        if ($u === '') return '';
        if (!preg_match('#^https?://#i', $u)) return '';
        return $u;
    }

    /** Hosts currently eligible — everything not parked after a failure. */
    public static function healthy(): array
    {
        $all = self::all();
        if ($all === []) return [];
        $up = array_values(array_filter($all, fn($h) => !self::isParked($h)));
        // Everything parked (e.g. a total outage) — try them all again rather
        // than refuse to make any call at all.
        return $up !== [] ? $up : $all;
    }

    /** Configured per-host ceiling in calls/second. 0 disables limiting. */
    public static function ratePerSec(): int
    {
        return max(0, (int) (Settings::get(self::RATE_SETTING, 0) ?? 0));
    }

    /**
     * Claim a request slot on a host for the current second.
     * Returns false when that host is already at its ceiling.
     */
    private static function claim(string $host): bool
    {
        $limit = self::ratePerSec();
        if ($limit <= 0) return true;                 // limiting disabled

        $key = 'apipool:rate:' . md5($host) . ':' . time();
        try {
            // add() is atomic; seeds the window on first call of this second.
            if (Cache::add($key, 1, 2)) return true;
            $n = Cache::increment($key);
            if (!is_int($n)) return true;             // driver quirk: don't block
            return $n <= $limit;
        } catch (\Throwable $e) {
            return true;                              // cache down: never block traffic
        }
    }

    /**
     * Next host for an outbound call, or null when no pool is configured
     * (caller then uses its own single configured base URL).
     *
     * Rotation and rate limiting compose: a host at its ceiling is simply
     * skipped, so load moves to the next one instead of erroring. Only when
     * EVERY host is saturated do we wait briefly, then fail.
     */
    public static function next(): ?string
    {
        $pool = self::healthy();
        if ($pool === []) return null;

        $deadline = microtime(true) + (self::MAX_WAIT_MS / 1000);
        do {
            $count = count($pool);
            for ($i = 0; $i < $count; $i++) {
                $host = $pool[self::cursor() % $count];
                if (self::claim($host)) return $host;
            }
            // Every host is at its ceiling — pause briefly and retry rather
            // than hammering, which is the behaviour the limit exists to stop.
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::$saturated = true;
        Log::warning('ApiServerPool: every host at its rate limit', [
            'hosts' => count($pool), 'limit_per_sec' => self::ratePerSec(),
        ]);
        // Return a host anyway: shedding the request is the caller's decision
        // (see wasSaturated()), not something to decide deep in a URL builder.
        return $pool[self::cursor() % count($pool)];
    }

    /** True when the last next() could not find a free slot. */
    public static ?bool $saturated = false;

    public static function wasSaturated(): bool
    {
        return (bool) self::$saturated;
    }

    public static function resetSaturated(): void
    {
        self::$saturated = false;
    }

    /** Shared round-robin cursor; atomic across workers. */
    private static function cursor(): int
    {
        try {
            $n = Cache::increment(self::CURSOR);
            if (!is_int($n)) { Cache::put(self::CURSOR, 1, 86400); $n = 1; }
            return $n;
        } catch (\Throwable $e) {
            return random_int(0, 100000);   // cache down: still spread
        }
    }

    /**
     * Swap the host portion of a URL for a pooled one, keeping the path.
     * Returns the original when no pool is configured.
     */
    public static function rewrite(string $url): string
    {
        $host = self::next();
        if ($host === null) return $url;

        $p = parse_url($url);
        if ($p === false || empty($p['path'])) return $host;
        $q = isset($p['query']) ? '?' . $p['query'] : '';
        return rtrim($host, '/') . $p['path'] . $q;
    }

    /** Park a host after a connection failure. */
    public static function penalize(?string $url): void
    {
        $host = self::hostOf($url);
        if ($host === '') return;
        try {
            Cache::put(self::parkKey($host), 1, self::PENALTY_SECONDS);
            Log::warning('ApiServerPool: host parked after failure', [
                'host' => $host, 'seconds' => self::PENALTY_SECONDS,
            ]);
        } catch (\Throwable $e) { /* parking is an optimisation, never fatal */ }
    }

    public static function isParked(string $url): bool
    {
        $host = self::hostOf($url);
        if ($host === '') return false;
        try { return (bool) Cache::get(self::parkKey($host)); }
        catch (\Throwable $e) { return false; }
    }

    /** Status for the superadmin UI: each host and whether it is parked. */
    public static function status(): array
    {
        $limit = self::ratePerSec();
        return array_map(function ($h) use ($limit) {
            $used = 0;
            if ($limit > 0) {
                try { $used = (int) (Cache::get('apipool:rate:' . md5($h) . ':' . time(), 0)); }
                catch (\Throwable $e) {}
            }
            return [
                'url' => $h,
                'parked' => self::isParked($h),
                'used_this_second' => $used,
                'limit_per_sec' => $limit,
            ];
        }, self::all());
    }

    /** Clear all penalties (superadmin "reset" action). */
    public static function clearPenalties(): void
    {
        foreach (self::all() as $h) {
            try { Cache::forget(self::parkKey($h)); } catch (\Throwable $e) {}
        }
    }

    private static function hostOf(?string $url): string
    {
        $p = parse_url((string) $url);
        if (!$p || empty($p['host'])) return '';
        $scheme = $p['scheme'] ?? 'https';
        $port = isset($p['port']) ? ':' . $p['port'] : '';
        return "{$scheme}://{$p['host']}{$port}";
    }

    private static function parkKey(string $host): string
    {
        return 'apipool:down:' . md5($host);
    }
}

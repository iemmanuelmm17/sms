<?php

namespace App\Services;

use App\Models\LoginAttempt;

/** 3 consecutive failures -> 5-minute lockout (per username; IP as backstop). */
class LockoutService
{
    public const MAX_FAILS = 3;
    public const LOCK_SECS = 300;
    public const IP_MAX_FAILS = 15;

    public static function record(string $username, ?string $ip, bool $success): void
    {
        try {
            LoginAttempt::create([
                'username' => mb_strtolower(trim($username)), 'ip_address' => $ip,
                'attempted_at' => now(), 'success' => $success,
            ]);
        } catch (\Throwable $e) { /* auth must work even if audit storage hiccups */ }
    }

    /** Seconds remaining on the username lockout, 0 when clear. */
    public static function remainingFor(string $username): int
    {
        $fails = LoginAttempt::where('username', mb_strtolower(trim($username)))
            ->where('attempted_at', '>=', now()->subSeconds(self::LOCK_SECS))
            ->orderByDesc('attempted_at')
            ->limit(self::MAX_FAILS)
            ->get();
        // Only CONSECUTIVE leading failures count — any success breaks the chain.
        if ($fails->count() < self::MAX_FAILS || $fails->contains('success', true)) return 0;
        $since = now()->diffInSeconds($fails->first()->attempted_at);
        return max(0, self::LOCK_SECS - $since);
    }

    /** Secondary signal: too many failures from one IP. */
    public static function ipLocked(?string $ip): bool
    {
        if (!$ip) return false;
        return LoginAttempt::where('ip_address', $ip)
            ->where('success', false)
            ->where('attempted_at', '>=', now()->subSeconds(self::LOCK_SECS))
            ->count() >= self::IP_MAX_FAILS;
    }

    /** Currently-locked usernames + IPs scoped to one tenant domain. */
    public static function lockedForDomain(string $domain): array
    {
        $since = now()->subSeconds(self::LOCK_SECS);
        $like = '%' . addcslashes('@' . mb_strtolower($domain), '%_\\');
        $out = ['users' => [], 'ips' => []];
        $users = LoginAttempt::where('attempted_at', '>=', $since)
            ->where('username', 'like', $like)->distinct()->pluck('username');
        foreach ($users as $u) {
            if ($secs = self::remainingFor($u)) {
                $fails = LoginAttempt::where('username', $u)->where('success', false)
                    ->where('attempted_at', '>=', $since)->count();
                $out['users'][] = ['username' => $u, 'fails' => $fails, 'retry_after_secs' => $secs];
            }
        }
        $ips = LoginAttempt::where('attempted_at', '>=', $since)
            ->where('username', 'like', $like)->whereNotNull('ip_address')
            ->distinct()->pluck('ip_address');
        foreach ($ips as $ip) {
            if (!self::ipLocked($ip)) continue;
            $asc = LoginAttempt::where('ip_address', $ip)->where('success', false)
                ->where('attempted_at', '>=', $since)->orderBy('attempted_at')
                ->pluck('attempted_at')->map(fn($t) => \Carbon\Carbon::parse($t))->values();
            $n = $asc->count();
            // Locked until fails-in-window drop below 15: expiry of fail #(n-15).
            $target = $asc->get(max(0, $n - self::IP_MAX_FAILS));
            $retry = $target ? max(0, self::LOCK_SECS - now()->diffInSeconds($target)) : self::LOCK_SECS;
            $out['ips'][] = ['ip' => $ip, 'fails' => $n, 'retry_after_secs' => $retry];
        }
        return $out;
    }

    public static function clearUser(string $username): int
    {
        return LoginAttempt::where('username', mb_strtolower(trim($username)))->delete();
    }

    public static function clearIp(string $ip): int
    {
        return LoginAttempt::where('ip_address', trim($ip))->delete();
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\SuperAdminAllowedIp;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The superadmin portal answers only to localhost + the DB allowlist.
 * Deploy note: behind a proxy, configure trusted proxies or this sees
 * the proxy's IP. IPv6 works as exact entries; CIDR ranges are IPv4-only.
 */
class EnsureSuperAdminIp
{
    public function handle(Request $request, Closure $next)
    {
        $ip = (string) $request->ip();
        if ($ip === '127.0.0.1' || $ip === '::1') return $next($request);
        try {
            $allowed = Cache::remember('superadmin:ips', 300,
                fn() => SuperAdminAllowedIp::query()->pluck('cidr')->all());
        } catch (\Throwable $e) {
            $allowed = []; // table missing → localhost only
        }
        foreach ($allowed as $cidr) {
            if (self::matches($ip, (string) $cidr)) return $next($request);
        }
        abort(403, 'Access restricted.');
    }

    public static function matches(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);
        if ($cidr === '') return false;
        if (!str_contains($cidr, '/')) return hash_equals($cidr, $ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        if (!filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        $bits = (int) $bits;
        if ($bits < 0 || $bits > 32) return false;
        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
        return (ip2long($ip) & $mask) === (ip2long($net) & $mask);
    }

    /** Shared format check: plain IP (v4/v6) or IPv4 CIDR. */
    public static function valid(string $cidr): bool
    {
        $cidr = trim($cidr);
        if ($cidr === '') return false;
        if (str_contains($cidr, '/')) {
            [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
            return (bool) filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                && is_numeric($bits) && (int) $bits >= 0 && (int) $bits <= 32;
        }
        return (bool) filter_var($cidr, FILTER_VALIDATE_IP);
    }
}

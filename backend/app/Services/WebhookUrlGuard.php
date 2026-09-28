<?php

namespace App\Services;

/**
 * SSRF guard for tenant-controlled callback URLs (outbound webhooks).
 * Every delivery hop (initial URL + each redirect) must be http(s) to a
 * hostname resolving ONLY to public IPs. Fail closed throughout.
 *
 * Residual: DNS-rebinding TOCTOU (validated IP vs connected IP can differ
 * within a TTL). Accepted at this threat level; revisit if tenants are
 * ever untrusted (pin resolved IP + Host header then).
 */
class WebhookUrlGuard
{
    /**
     * @throws \InvalidArgumentException blocked target (fail fast, no retry)
     * @throws \RuntimeException host won't resolve (retryable)
     */
    public static function assertPublicUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)) throw new \InvalidArgumentException('blocked webhook URL');
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('blocked webhook URL');
        }
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            foreach ((array) (gethostbynamel($host) ?: []) as $ip) $ips[] = $ip;
            try {
                foreach ((array) (dns_get_record($host, DNS_AAAA) ?: []) as $r) {
                    if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
                }
            } catch (\Throwable $e) {}
        }
        $ips = array_values(array_unique($ips));
        if ($ips === []) throw new \RuntimeException('webhook host does not resolve');
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \InvalidArgumentException('blocked webhook target');
            }
        }
    }

    /** Resolve absolute, protocol-relative, or relative Location against the current URL. */
    public static function resolveRedirect(string $current, string $loc): string
    {
        $loc = trim($loc);
        if (preg_match('#^https?://#i', $loc)) return $loc;
        $p = parse_url($current);
        if (!is_array($p)) return $loc;
        if (str_starts_with($loc, '//')) return ($p['scheme'] ?? 'https') . ':' . $loc;
        $base = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
        if (isset($p['port'])) $base .= ':' . $p['port'];
        if (str_starts_with($loc, '/')) return $base . $loc;
        $dir = rtrim(dirname((string) ($p['path'] ?? '/')), '/');
        return $base . ($dir === '' ? '' : $dir) . '/' . ltrim($loc, '/');
    }
}

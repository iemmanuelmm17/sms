<?php

/*
 | Cross-Origin Resource Sharing (CORS)
 |
 | bootstrap/app.php prepends HandleCors, but without this file the framework
 | defaults applied: only `api/*` paths, no credentials. That left
 | /broadcasting/auth preflights falling through to the manual OPTIONS route
 | in routes/web.php, which hardcoded http://localhost:5173 — any LAN/other
 | origin was rejected. This config covers the API and the broadcasting auth
 | endpoint, reflects the request Origin back, and allows credentials — the
 | right posture for a self-hosted app whose session cookie is SameSite=lax:
 | cross-site fetches never carry the cookie, so a reflected origin cannot
 | expose authenticated data to a malicious site. Same-origin setups (vite
 | proxy GETs) are unaffected — no Origin header, no CORS headers emitted.
 |
 | HISTORY: this file once shipped `'allowed_origins_patterns' => ['*']`.
 | fruitcake/php-cors feeds every entry straight into preg_match(), and a
 | bare `*` has no regex delimiters — EVERY request carrying an Origin
 | header (notably POST /broadcasting/auth, which browsers send Origin on
 | even same-origin) died with "preg_match(): No ending delimiter" → 500,
 | while Origin-less GETs kept working. Entries here MUST be complete,
 | delimited PCRE patterns. `php artisan realtime:doctor` validates them.
 */

// Optional exact origins from .env, comma-separated
// (e.g. CORS_ALLOWED_ORIGINS=https://sms.example.com). The reflect-any
// pattern below already covers http(s) origins; this exists for completeness.
$extraOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

return [
    'paths' => ['api/*', 'broadcasting/*', 'sanctum/csrf-cookie', 'up'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $extraOrigins,
    'allowed_origins_patterns' => [
        // Reflect any http(s) origin — LAN IPs, public IPs, domains, any port.
        '#^https?://.+$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => true,
];

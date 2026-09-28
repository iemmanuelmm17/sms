<?php

/*
 | Cross-Origin Resource Sharing (CORS)
 |
 | bootstrap/app.php prepends HandleCors, but without this file the framework
 | defaults applied: only `api/*` paths, no credentials. That left
 | /broadcasting/auth preflights falling through to the manual OPTIONS route
 | in routes/web.php, which hardcoded http://localhost:5173 — any LAN/other
 | origin was rejected. This config covers the API and the broadcasting auth
 | endpoint, reflects any origin (pattern '*') and allows credentials, which
 | is the right posture for a self-hosted LAN app whose session cookie is
 | SameSite=lax. Same-origin setups (vite proxy) are unaffected — no Origin
 | header, no CORS headers emitted.
 */

return [
    'paths' => ['api/*', 'broadcasting/*', 'sanctum/csrf-cookie', 'up'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [],
    'allowed_origins_patterns' => ['*'],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => true,
];

<?php

namespace App\Services;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\ApiServerPool;

/**
 * Dynalink NS-API v2 client.
 *
 * Wraps the raw Dynalink endpoints documented in:
 *  token.txt, messages.txt, contact.txt, getsmsnumber.txt, eventsubscription.txt
 *
 * All calls use the end-user's Dynalink access_token (stored in session
 * after login) so every request is scoped to that user+domain.
 */
class DynalinkService
{
    protected string $coreBase;
    protected string $authBase;
    protected string $clientId;
    protected string $clientSecret;

    public function __construct()
    {
        $this->coreBase     = rtrim(config('services.dynalink.core_base', 'https://core2-nyc.dynalink.net/ns-api/v2'), '/');
        $this->authBase     = rtrim(config('services.dynalink.auth_base', 'https://nms1.nyc.birns.net/ns-api/v2'), '/');
        $this->clientId     = Settings::get('dynalink.client_id', config('services.dynalink.client_id', 'report'));
        $this->clientSecret = Settings::get('dynalink.client_secret', config('services.dynalink.client_secret', ''));
    }

    /* ------------------------------------------------------------------
     | Auth / Tokens (token.txt)
     * ------------------------------------------------------------------ */

    /** Password-grant login → returns full token payload. */
    public function login(string $username, string $password): array
    {
        $res = $this->authApi()->post($this->authHost() . "/tokens", [
            'grant_type'    => 'password',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username'      => $username,   // e.g. 6001@1234.ExampleCo
            'password'      => $password,
        ]);

        if ($res->failed()) {
            \Illuminate\Support\Facades\Log::warning('Dynalink login failed', ['status' => $res->status()]);
            abort(response()->json(['message' => 'Incorrect username and Password'], 401));
        }

        return $res->json();
    }

    /** Refresh-token grant. */
    public function refreshToken(string $refreshToken): array
    {
        $res = $this->authApi()->post($this->authHost() . "/tokens", [
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
        ]);

        if ($res->failed()) {
            abort(response()->json(['message' => 'Session expired, please log in again.'], 401));
        }

        return $res->json();
    }

    /* ------------------------------------------------------------------
     | Low-level helpers
     * ------------------------------------------------------------------ */

    protected function api(string $token)
    {
        return Http::acceptJson()->withToken($token)->timeout(30)
            ->withMiddleware($this->providerGuard());
    }

    protected function authApi()
    {
        return Http::acceptJson()->timeout(10)->withMiddleware($this->providerGuard());
    }

    /**
     * Host used to mint tokens.
     *
     * Deliberately NOT rotated by default: an access token issued by one node
     * is only reusable on another if the cluster shares session state. Rotating
     * blindly would produce intermittent 401s that look like random logouts.
     * Set DYNALINK_POOL_AUTH=true once you have confirmed tokens are portable.
     */
    protected function authHost(): string
    {
        if (!filter_var(config('services.dynalink.pool_auth', false), FILTER_VALIDATE_BOOL)) {
            return $this->authBase;
        }
        $pooled = ApiServerPool::next();
        if ($pooled === null) return $this->authBase;
        $path = parse_url($this->authBase, PHP_URL_PATH) ?: '';
        return rtrim($pooled, '/') . $path;
    }

    /**
     * Guzzle middleware: every provider connection failure (DNS/TCP/TLS/
     * timeout — the "cURL error N" family) becomes one friendly 503.
     * Technical detail goes to the log only, never to the API response.
     * Handles both sync throws and async rejections.
     */
    protected function providerGuard(): callable
    {
        return function (callable $handler) {
            return function ($request, array $options) use ($handler) {
                try {
                    $result = $handler($request, $options);
                } catch (ConnectException $e) {
                    $this->abortUnreachable($e);
                }
                if ($result instanceof \GuzzleHttp\Promise\PromiseInterface) {
                    return $result->otherwise(function ($reason) {
                        if ($reason instanceof ConnectException) $this->abortUnreachable($reason);
                        return new \GuzzleHttp\Promise\RejectedPromise($reason);
                    });
                }
                return $result;
            };
        };
    }

    protected function abortUnreachable(ConnectException $e): never
    {
        // Take the failing host out of rotation briefly, so the pool stops
        // feeding a predictable share of requests into a dead node.
        try {
            $uri = method_exists($e, 'getRequest') ? (string) $e->getRequest()->getUri() : '';
            if ($uri !== '') ApiServerPool::penalize($uri);
        } catch (\Throwable $ignored) {}
        Log::warning('Dynalink unreachable', ['error' => $e->getMessage()]);
        abort(response()->json([
            'message' => "We couldn't reach the messaging provider. Please try again in a moment.",
            'code' => 'provider_unreachable',
        ], 503));
    }

    /**
     * Base host for domain/user scoped endpoints (smsnumbers, messagesessions,
     * messages, contacts).
     *
     * Defaults to authBase — the NMS host that issues the token and serves the
     * documented /domains/... endpoints. Set DYNALINK_USE_CORE_FOR_USER=true to
     * restore the old coreBase behaviour if a deployment needs it.
     */
    protected function domainHost(): string
    {
        $base = filter_var(config('services.dynalink.use_core_for_user', false), FILTER_VALIDATE_BOOL)
            ? $this->coreBase
            : $this->authBase;

        // Spread read/write traffic over the superadmin's server pool. Returns
        // $base untouched when no pool is configured.
        $pooled = ApiServerPool::next();
        if ($pooled === null) return $base;

        // Keep the path (e.g. /ns-api/v2) from the configured base — pool
        // entries are hosts, and losing the API prefix would 404 everything.
        $path = parse_url($base, PHP_URL_PATH) ?: '';
        return rtrim($pooled, '/') . $path;
    }

    protected function userPath(string $domain, string $user): string
    {
        return "{$this->domainHost()}/domains/{$domain}/users/{$user}";
    }

    /* ------------------------------------------------------------------
     | SMS numbers (getsmsnumber.txt)
     * ------------------------------------------------------------------ */

    /**
     * NS-API list endpoints page at 100 by default, which silently truncated
     * the number inventory on larger domains. Every smsnumbers call passes an
     * explicit limit so the full list comes back in one request.
     */
    public const NUMBERS_LIMIT = 999;

    /** Number inventory rarely changes: 5-min cache, TTL-only (provisioning happens outside the app). */
    public function smsNumbers(string $token, string $domain, string $user): array
    {
        return \Illuminate\Support\Facades\Cache::remember("dl:numbers:{$domain}:{$user}", 300,
            fn() => $this->asList($this->api($token)
                ->get($this->userPath($domain, $user) . '/smsnumbers', ['limit' => self::NUMBERS_LIMIT])
                ->json()));
    }

    /**
     * Every SMS number on the domain — the admin Numbers page inventory.
     *
     *   GET /domains/{domain}/smsnumbers
     *
     * This is domain-scoped, not user-scoped, so it returns numbers the
     * calling identity is not personally assigned. Admin-only by routing.
     */
    public function domainSmsNumbers(string $token, string $domain): array
    {
        // NOTE: domain-scoped endpoints live on the NMS host (authBase), the
        // same host that issued the token — not coreBase. Hitting coreBase
        // here returned nothing for domains whose inventory is on NMS, which
        // made accounts look like they had no SMS numbers at all.
        return \Illuminate\Support\Facades\Cache::remember("dl:dnumbers:{$domain}", 300,
            fn() => $this->asList($this->api($token)
                ->get("{$this->domainHost()}/domains/{$domain}/smsnumbers", ['limit' => self::NUMBERS_LIMIT])
                ->json()));
    }

    /**
     * One portal user's profile.
     *
     *   GET /domains/{domain}/users/{ext}
     *
     * Source of the agent's real name — without it a signature falls back to
     * the bare extension. Cached briefly: it is read on login and on resync,
     * and the portal is the system of record so it rarely changes.
     */
    public function userProfile(string $token, string $domain, string $ext): array
    {
        $res = $this->api($token)->get($this->userPath($domain, $ext));
        if ($res->failed()) return [];
        $d = $res->json();
        return is_array($d) ? $d : [];
    }

    /**
     * Shape a portal profile into the fields we store locally.
     * Missing keys degrade to null rather than throwing.
     */
    public static function shapeUser(array $row): array
    {
        $first = trim((string) ($row['name-first-name'] ?? ''));
        $last  = trim((string) ($row['name-last-name'] ?? ''));
        $full  = trim($first . ' ' . $last);
        return [
            'first_name'  => $first !== '' ? $first : null,
            'last_name'   => $last !== '' ? $last : null,
            'full_name'   => $full !== '' ? $full : null,
            'email'       => trim((string) ($row['email'] ?? '')) ?: null,
            'department'  => trim((string) ($row['department'] ?? '')) ?: null,
            'site'        => trim((string) ($row['site'] ?? '')) ?: null,
        ];
    }

    /**
     * digits => owning extension ("dest") for every SMS number on the domain.
     *
     * The NS-API scopes message sessions per USER, and a number's sessions live
     * under the extension the number is assigned to — not under the admin who
     * is looking at them. This map is how an admin view resolves the right
     * $user for a given number.
     */
    public function numberOwners(string $token, string $domain): array
    {
        // Cached as a derived map: the inventory behind it is already cached,
        // but rebuilding this on every session request costs a full re-parse
        // of the domain list on large accounts.
        return \Illuminate\Support\Facades\Cache::remember("dl:owners:{$domain}", 300, function () use ($token, $domain) {
            try {
                $map = [];
                foreach ($this->domainSmsNumbers($token, $domain) as $row) {
                    if (!is_array($row)) continue;
                    $d = preg_replace('/\D/', '', (string) ($row['number'] ?? ''));
                    $dest = isset($row['dest']) ? trim((string) $row['dest']) : '';
                    if ($d !== '' && $dest !== '') $map[$d] = $dest;
                }
                // Last-known-good copy: a provider hiccup must not empty the
                // map and strip agents of their OWN numbers mid-session —
                // that surfaced as spurious 403s opening conversations.
                \Illuminate\Support\Facades\Cache::put("dl:owners:last:{$domain}", $map, now()->addDay());
                return $map;
            } catch (\Throwable $e) {
                $last = \Illuminate\Support\Facades\Cache::get("dl:owners:last:{$domain}");
                if (is_array($last)) {
                    Log::warning('numberOwners: provider failed, serving last-known-good map', [
                        'domain' => $domain, 'error' => $e->getMessage(),
                    ]);
                    return $last;
                }
                throw $e;
            }
        }) ?? [];
    }

    /** Owning extension for one number, or null when it is unassigned/unknown. */
    public function numberOwner(string $token, string $domain, string $digits): ?string
    {
        return $this->numberOwners($token, $domain)[$digits] ?? null;
    }

    public static function forgetDomainNumbers(string $domain): void
    {
        try {
            \Illuminate\Support\Facades\Cache::forget("dl:dnumbers:{$domain}");
            \Illuminate\Support\Facades\Cache::forget("dl:owners:{$domain}");   // derived from the same data
        } catch (\Throwable $e) {}
    }

    /**
     * Normalize one NS-API smsnumber row to the four fields the UI shows.
     * Unknown/missing keys degrade to null rather than throwing, because
     * the provider omits fields on some carriers.
     */
    public static function shapeNumber(array $row): array
    {
        $num = (string) ($row['number'] ?? '');
        return [
            'number'             => $num,
            'digits'             => preg_replace('/\D/', '', $num),
            'dest'               => isset($row['dest']) ? (string) $row['dest'] : null,
            'mms_capable'        => filter_var($row['mms-capable'] ?? false, FILTER_VALIDATE_BOOL),
            'group_mms_capable'  => filter_var($row['group-mms-capable'] ?? false, FILTER_VALIDATE_BOOL),
            'application'        => $row['application'] ?? null,
            'carrier'            => $row['carrier'] ?? null,
            'domain'             => $row['domain'] ?? null,
        ];
    }

    /* ------------------------------------------------------------------
     | Message sessions + messages (messages.txt)
     * ------------------------------------------------------------------ */

    /** Sessions-list cache: short TTL, invalidated on every mutation path. */
    public const SESSIONS_TTL = 45;

    public static function sessionsKey(string $domain, string $user): string
    {
        return 'sess:list:' . md5(strtolower($domain) . '|' . strtolower($user));
    }

    public static function forgetSessions(string $domain, string $user): void
    {
        try { \Illuminate\Support\Facades\Cache::forget(self::sessionsKey($domain, $user)); } catch (\Throwable $e) {}
    }

    public function sessions(string $token, string $domain, string $user, ?int $limit = null): array
    {
        $key = self::sessionsKey($domain, $user) . ($limit ? ":l{$limit}" : '');
        $fetch = function () use ($token, $domain, $user, $limit) {
            $url = $this->userPath($domain, $user) . '/messagesessions';
            $res = $limit
                ? $this->api($token)->get($url, ['limit' => $limit])
                : $this->api($token)->get($url);
            return $this->asList($res->json());
        };
        try {
            return \Illuminate\Support\Facades\Cache::remember($key, self::SESSIONS_TTL, $fetch) ?? [];
        } catch (\Throwable $e) {
            return $fetch();
        }
    }

    public function sessionMessages(string $token, string $domain, string $user, string $sessionId, ?int $limit = null): array
    {
        return $this->asList($this->api($token)
            ->get($this->userPath($domain, $user) . "/messagesessions/{$sessionId}/messages",
                ['limit' => $limit ?: self::NUMBERS_LIMIT])
            ->json());
    }

    /**
     * Send inside an existing session.
     * $sessionId must be >= 32 chars, [A-Za-z0-9_].
     */
    public function sendInSession(string $token, string $domain, string $user, string $sessionId, array $payload): array
    {
        $res = $this->api($token)->post(
            $this->userPath($domain, $user) . "/messagesessions/{$sessionId}/messages",
            $payload
        );
        if ($res->successful()) self::forgetSessions($domain, $user);
        return [$res->status(), $res->json() ?? $res->body()];
    }

    /** Send a brand-new message (API generates the session). */
    public function sendNew(string $token, string $domain, string $user, array $payload): array
    {
        $res = $this->api($token)->post($this->userPath($domain, $user) . '/messages', $payload);
        if ($res->successful()) self::forgetSessions($domain, $user);
        return [$res->status(), $res->json() ?? $res->body()];
    }

    /** Random 32-char session id for client-created sessions. */
    public static function randomSessionId(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';
        $id = '';
        for ($i = 0; $i < 32; $i++) {
            $id .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $id;
    }

    /* ------------------------------------------------------------------
     | Contacts (contact.txt)
     * ------------------------------------------------------------------ */

    public function contacts(string $token, string $domain, string $user): array
    {
        return \Illuminate\Support\Facades\Cache::remember("dl:contacts:{$domain}:{$user}", 120, function () use ($token, $domain, $user) {
            $res = $this->api($token)->get($this->userPath($domain, $user) . '/contacts',
                ['limit' => self::NUMBERS_LIMIT]);
            $data = $res->json();
            // API sometimes returns a single object instead of array
            if (isset($data['uid']) || isset($data['unique-id'])) {
                return [$data];
            }
            return $data ?? [];
        });
    }

    public static function bustContacts(string $domain, string $user): void
    {
        try { \Illuminate\Support\Facades\Cache::forget("dl:contacts:{$domain}:{$user}"); } catch (\Throwable $e) {}
    }

    public function createContact(string $token, string $domain, string $user, array $payload): array
    {
        $res = $this->api($token)->post($this->userPath($domain, $user) . '/contacts', $payload);
        static::bustContacts($domain, $user);
        return [$res->status(), $res->json() ?? $res->body()];
    }

    public function updateContact(string $token, string $domain, string $user, string $contactId, array $payload): array
    {
        $res = $this->api($token)->put($this->userPath($domain, $user) . "/contacts/{$contactId}", $payload);
        static::bustContacts($domain, $user);
        return [$res->status(), $res->json() ?? $res->body()];
    }

    /** Mark a conversation read/unread IN THE PORTAL (202 on success). */
    public function setSessionStatus(string $token, string $domain, string $user, string $sessionId, string $status): array
    {
        $res = $this->api($token)->put(
            $this->userPath($domain, $user) . "/messagesessions/{$sessionId}",
            ['messagesession-last-status' => $status]
        );
        if ($res->successful()) self::forgetSessions($domain, $user);
        return [$res->status(), $res->json() ?? $res->body()];
    }

    public function deleteContact(string $token, string $domain, string $user, string $contactId): array
    {
        $res = $this->api($token)->delete($this->userPath($domain, $user) . "/contacts/{$contactId}");
        static::bustContacts($domain, $user);
        return [$res->status(), $res->json() ?? $res->body()];
    }

    /* ------------------------------------------------------------------
     | Shared (domain-level) contacts — /domains/{domain}/contacts
     | The SECOND Dynalink address book: no user segment. WRITES only —
     | reads come back mixed into the personal contacts GET (directory
     | rows key their id as `uid` instead of `unique-id`).
     * ------------------------------------------------------------------ */

    protected function domainContactsPath(string $domain): string
    {
        return "{$this->domainHost()}/domains/{$domain}/contacts";
    }

    public function createDomainContact(string $token, string $domain, array $payload): array
    {
        $res = $this->api($token)->post($this->domainContactsPath($domain), $payload);
        return [$res->status(), $res->json() ?? $res->body()];
    }

    public function updateDomainContact(string $token, string $domain, string $contactId, array $payload): array
    {
        $res = $this->api($token)->put($this->domainContactsPath($domain) . "/{$contactId}", $payload);
        return [$res->status(), $res->json() ?? $res->body()];
    }

    public function deleteDomainContact(string $token, string $domain, string $contactId): array
    {
        $res = $this->api($token)->delete($this->domainContactsPath($domain) . "/{$contactId}");
        return [$res->status(), $res->json() ?? $res->body()];
    }

    /* ------------------------------------------------------------------
     | Event subscriptions / webhooks (eventsubscription.txt)
     * ------------------------------------------------------------------ */

    public function createSubscription(string $token, array $payload): array
    {
        $res = $this->api($token)->post("{$this->coreBase}/subscriptions", $payload);
        return [$res->status(), $res->json() ?? $res->body()];
    }

    public function getSubscription(string $token, string $id): array
    {
        return $this->api($token)->get("{$this->coreBase}/subscriptions/{$id}")->json() ?? [];
    }

    public function updateSubscription(string $token, string $id, array $payload): array
    {
        $res = $this->api($token)->put("{$this->coreBase}/subscriptions/{$id}", $payload);
        return [$res->status(), $res->json() ?? $res->body()];
    }

    public function deleteSubscription(string $token, string $id): array
    {
        $res = $this->api($token)->delete("{$this->coreBase}/subscriptions/{$id}");
        return [$res->status(), $res->json() ?? $res->body()];
    }

    /** Best-effort list — used to adopt server-side subs the cache lost (409s). */
    public function listSubscriptions(string $token): array
    {
        $res = $this->api($token)->get("{$this->coreBase}/subscriptions");
        return [$res->status(), $res->json() ?? $res->body()];
    }

    /** Renew (extend expiry) — call whenever the session token is refreshed. */
    public function renewSubscription(string $token, string $id, string $postUrl, string $model = 'message'): array
    {
        return $this->updateSubscription($token, $id, [
            'model'                        => $model,
            'post-url'                     => $postUrl,
            'subscription-expires-datetime' => now()->addYears(10)->format('Y-m-d H:i:s'),
        ]);
    }

	    /**
     * Dynalink list endpoints occasionally return a single object or an
     * error object instead of a JSON list — normalize to a plain list so
     * API consumers always receive an array (never a crash-causing object).
     */
    protected function asList(mixed $data): array
    {
        if (is_array($data) && array_is_list($data)) {
            return $data;
        }
        // Single object → wrap. Covers contacts (uid/unique-id), the smsnumbers
        // endpoints (number), and message sessions (messagesession-id), any of
        // which return a bare object when the collection has exactly one row.
        if (is_array($data) && (isset($data['uid']) || isset($data['unique-id'])
            || isset($data['number']) || isset($data['messagesession-id']))) {
            return [$data];
        }
        return [];
    }
}

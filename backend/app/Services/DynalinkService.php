<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

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
        $res = Http::acceptJson()->timeout(10)->post("{$this->authBase}/tokens", [
            'grant_type'    => 'password',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username'      => $username,   // e.g. 6001@1180.DynaCloud
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
        $res = Http::acceptJson()->timeout(10)->post("{$this->authBase}/tokens", [
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
        return Http::acceptJson()->withToken($token)->timeout(30);
    }

    protected function userPath(string $domain, string $user): string
    {
        return "{$this->coreBase}/domains/{$domain}/users/{$user}";
    }

    /* ------------------------------------------------------------------
     | SMS numbers (getsmsnumber.txt)
     * ------------------------------------------------------------------ */

    public function smsNumbers(string $token, string $domain, string $user): array
    {
        return $this->api($token)->get($this->userPath($domain, $user) . '/smsnumbers')->json() ?? [];
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

    public function sessions(string $token, string $domain, string $user): array
    {
        $key = self::sessionsKey($domain, $user);
        try {
            return \Illuminate\Support\Facades\Cache::remember($key, self::SESSIONS_TTL, function () use ($token, $domain, $user) {
                return $this->api($token)->get($this->userPath($domain, $user) . '/messagesessions')->json() ?? [];
            }) ?? [];
        } catch (\Throwable $e) {
            return $this->api($token)->get($this->userPath($domain, $user) . '/messagesessions')->json() ?? [];
        }
    }

    public function sessionMessages(string $token, string $domain, string $user, string $sessionId): array
    {
        return $this->api($token)
            ->get($this->userPath($domain, $user) . "/messagesessions/{$sessionId}/messages")
            ->json() ?? [];
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
        $res = $this->api($token)->get($this->userPath($domain, $user) . '/contacts');
        $data = $res->json();
        // API sometimes returns a single object instead of array
        if (isset($data['uid']) || isset($data['unique-id'])) {
            return [$data];
        }
        return $data ?? [];
    }

    public function createContact(string $token, string $domain, string $user, array $payload): array
    {
        $res = $this->api($token)->post($this->userPath($domain, $user) . '/contacts', $payload);
        return [$res->status(), $res->json() ?? $res->body()];
    }

    public function updateContact(string $token, string $domain, string $user, string $contactId, array $payload): array
    {
        $res = $this->api($token)->put($this->userPath($domain, $user) . "/contacts/{$contactId}", $payload);
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
        // Single contact object → wrap.
        if (is_array($data) && (isset($data['uid']) || isset($data['unique-id']))) {
            return [$data];
        }
        return [];
    }
}

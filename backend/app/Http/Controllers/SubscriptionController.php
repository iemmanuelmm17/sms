<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Models\TenantAdmin;
use App\Services\DynalinkService;
use App\Services\Settings;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Manages Dynalink event subscriptions (eventsubscription.txt).
 * Models used:
 *   - `message`        → 1 event per received chat/SMS message
 *   - `messagesession` → 1 event per session update (new msg / read mark)
 *
 * Creation failures are logged AND cached (per user) so the frontend
 * webhook-status card can show Dynalink's actual rejection reason —
 * previously they failed silently and the UI just said "not found".
 */
class SubscriptionController extends Controller
{
    use ResolvesActor;
    public function __construct(protected DynalinkService $dynalink) {}

    protected function sess(Request $r): array
    {
        // Actor-shaped session (domain/user/display_name/role). No bearer here —
        // Dynalink call sites use $this->dtoken(request()) so local-only reads
        // never touch the provider.
        return $this->actor($r);
    }

    /** GET /api/subscriptions — show current sub ids for this session. */
    public function index(Request $request)
    {
        $this->requireAdmin($this->actor($request));
        $s = $this->sess($request);
        $out = [];
        foreach (['message', 'messagesession'] as $model) {
            $id = Cache::get($this->cacheKey($s, $model));
            if ($id === 'external') {
                // Exists server-side (adopted after a 409) but Dynalink never
                // told us its id — display what we know.
                $out[$model] = [
                    'id' => 'external',
                    'status' => 'active',
                    'post-url' => $this->postUrl(),
                    'subscription-expires-datetime' => null,
                    'external' => true,
                ];
            } elseif ($id) {
                $out[$model] = $this->dynalink->getSubscription($this->dtoken(request()), $id);
            }
        }
        // Diagnostics for the webhook-status card.
        $out['_post_url'] = $this->postUrl();
        if ($err = Cache::get($this->errorKey($s))) {
            // A 409 ("already exists") is the healthy steady-state in
            // multi-login setups, not an error — never surface it, and
            // purge stale copies cached before adoption existed.
            if (($err['status'] ?? null) === 409) {
                Cache::forget($this->errorKey($s));
            } else {
                $out['_last_error'] = $err;
            }
        }
        return response()->json($out);
    }

    /**
     * POST /api/subscriptions/ensure — (re)create + renew both subscriptions
     * NOW and return the per-model outcome (no logout/login round-trip).
     */
    public function ensure(Request $request)
    {
        $this->requireAdmin($this->actor($request));
        $this->sess($request);
        return response()->json([
            'post_url' => $this->postUrl(),
            'results'  => $this->ensureForSession($request),
        ]);
    }

    /**
     * Ensure both subscriptions exist & are renewed.
     * Called on login and on every token refresh.
     * Returns per-model results: ['message' => ['action' => 'created|renewed|failed', ...]].
     */
    public function ensureForSession(Request $request): array
    {
        $s = $request->session()->get('dynalink');
        if (!$s && ($t = $request->session()->get('tenant'))) {
            // Tenant-admin session: same scope, token resolves via dtoken().
            $admin = TenantAdmin::with('tenant')->find($t['id'] ?? null);
            if ($admin && $admin->tenant) {
                $s = ['user' => $admin->tenant->dynalink_user, 'domain' => $admin->tenant->domain];
            }
        }
        if (!$s) return [];

        $postUrl = $this->postUrl();
        $results = [];

        foreach (['message', 'messagesession'] as $model) {
            $key = $this->cacheKey($s, $model);
            $existingId = Cache::get($key);

            if ($existingId === 'external') {
                // Adopted marker: the sub works server-side; nothing to do.
                $results[$model] = ['action' => 'kept-external', 'id' => 'external'];
                continue;
            }
            if ($existingId) {
                // Renew expiry alongside the session token refresh.
                [$status, $body] = $this->dynalink->renewSubscription(
                    $this->dtoken(request()), $existingId, $postUrl, $model
                );
                if ($status >= 200 && $status < 300) {
                    Cache::forget($this->errorKey($s));
                    $results[$model] = ['action' => 'renewed', 'id' => $existingId, 'status' => $status];
                    continue;
                }
                Cache::forget($key); // fall through to re-create
            }

            $results[$model] = $this->createEntry($this->dtoken(request()), $s, $model, $postUrl);
        }

        return $results;
    }

    /**
     * POST one subscription entry for ($s, $model) at $postUrl and cache its
     * id. Shared by ensureForSession (missing id) and recreateForScope
     * (post-url override changed). Returns the per-model result array.
     */
    protected function createEntry(string $token, array $s, string $model, string $postUrl): array
    {
        $key = $this->cacheKey($s, $model);
        [$status, $body] = $this->dynalink->createSubscription($token, [
            'model'                        => $model,
            'post-url'                     => $postUrl,
            'subscription-geo-support'     => 'no',
            'subscription-expires-datetime' => now()->addYears(10)->format('Y-m-d H:i:s'),
            'user'                         => $s['user'],
            'domain'                       => $s['domain'],
        ]);

        if ($status >= 200 && $status < 300 && isset($body['id'])) {
            Cache::put($key, $body['id'], now()->addYears(10));
            Cache::forget($this->errorKey($s));
            return ['action' => 'created', 'id' => $body['id'], 'status' => $status];
        }
        if ($status === 409) {
            // A working sub already exists server-side (the cache lost its
            // id) — adopt it instead of erroring.
            $adopted = $this->adoptExisting($token, $model, $postUrl);
            Cache::put($key, $adopted, now()->addYears(10));
            Cache::forget($this->errorKey($s));
            return ['action' => 'adopted', 'id' => $adopted, 'status' => $status];
        }
        Log::warning("Dynalink subscription create failed [{$model}]", [
            'status'   => $status,
            'response' => $body,
            'post_url' => $postUrl,
        ]);
        Cache::put($this->errorKey($s), [
            'model'    => $model,
            'status'   => $status,
            'response' => is_string($body) ? substr($body, 0, 1000) : $body,
            'post_url' => $postUrl,
            'at'       => now()->toDateTimeString(),
        ], now()->addDays(7));
        return ['action' => 'failed', 'status' => $status, 'response' => $body];
    }

    /**
     * Delete-then-create both subscriptions for one scope at the CURRENT
     * post-url. Used when the superadmin changes the webhook override so a
     * NEW Dynalink entry exists immediately (renew would only touch the
     * old entry). Never throws — per-model failures come back as results.
     */
    public function recreateForScope(string $domain, string $user, string $token): array
    {
        $s = ['domain' => $domain, 'user' => $user];
        $postUrl = $this->postUrl();
        $results = [];
        foreach (['message', 'messagesession'] as $model) {
            $key = $this->cacheKey($s, $model);
            $existingId = Cache::get($key);
            if ($existingId && $existingId !== 'external') {
                try {
                    $this->dynalink->deleteSubscription($token, (string) $existingId);
                } catch (\Throwable $e) {
                    Log::warning("Dynalink subscription delete-before-recreate failed [{$model}]", [
                        'id' => $existingId, 'error' => $e->getMessage(),
                    ]);
                }
            }
            Cache::forget($key);
            try {
                $results[$model] = $this->createEntry($token, $s, $model, $postUrl);
            } catch (\Throwable $e) {
                Log::warning("Dynalink subscription recreate failed [{$model}]", ['error' => $e->getMessage()]);
                $results[$model] = ['action' => 'failed', 'status' => 0, 'response' => substr($e->getMessage(), 0, 500)];
            }
            if (in_array($results[$model]['action'] ?? '', ['created', 'adopted'], true)) {
                DataChanged::send($domain, $user, 'subscriptions', 'recreated', $model);
            }
        }
        return $results;
    }

    /**
     * DELETE /api/subscriptions/{model} — delete the Dynalink subscription
     * for `message` or `messagesession` and forget the cached id.
     * A 404 from Dynalink (already gone) counts as success.
     */
    public function destroy(Request $request, string $model)
    {
        $actor = $this->actor($request);
        $this->requireAdmin($actor);
        abort_if(isset($actor['tenant_id']), 403, 'Subscription deletes are disabled for tenant admins.');
        $s = $this->sess($request);
        abort_unless(in_array($model, ['message', 'messagesession'], true), 404, 'Unknown subscription');
        $key = $this->cacheKey($s, $model);
        $id = Cache::get($key);
        if (! $id) {
            return response()->json(['ok' => true, 'note' => 'no cached subscription id']);
        }
        if ($id === 'external') {
            // Adopted marker — Dynalink never told us the real id, so there
            // is nothing addressable to delete. Clear + let Retry re-adopt.
            Cache::forget($key);
            Cache::forget($this->errorKey($s));
            return response()->json(['ok' => true, 'note' => 'externally managed subscription id unknown; cleared locally']);
        }
        [$status, $body] = $this->dynalink->deleteSubscription($this->dtoken(request()), $id);
        if (($status >= 200 && $status < 300) || $status === 404) {
            Cache::forget($key);
            Cache::forget($this->errorKey($s));
            DataChanged::send($s['domain'], $s['user'], 'subscriptions', 'deleted', $model);
            return response()->json(['ok' => true, 'status' => $status]);
        }
        Log::warning("Dynalink subscription delete failed [{$model}]", ['status' => $status, 'response' => $body]);
        return response()->json(['message' => 'Dynalink refused the delete', 'status' => $status, 'response' => $body], 422);
    }

    /**
     * A 409 means the sub already works server-side — find its id so we can
     * renew/track it. Falls back to an 'external' marker when Dynalink
     * won't list it (still clears the error loop; webhooks keep flowing).
     */
    protected function adoptExisting(string $token, string $model, string $postUrl): string
    {
        [$status, $body] = $this->dynalink->listSubscriptions($token);
        Log::info('Dynalink subscription list probe', ['status' => $status]);
        if ($status >= 200 && $status < 300 && is_array($body)) {
            $list = $body['subscriptions'] ?? $body['data'] ?? $body;
            if (is_array($list)) {
                foreach ($list as $sub) {
                    if (!is_array($sub)) continue;
                    if (($sub['model'] ?? null) === $model && ($sub['post-url'] ?? null) === $postUrl && !empty($sub['id'])) {
                        // Renew alongside adoption so the inherited expiry can't silently kill webhooks.
                        $this->dynalink->renewSubscription($token, $sub['id'], $postUrl, $model);
                        return (string) $sub['id'];
                    }
                }
            }
        }
        // List unsupported or no exact match — mark present so the UI stops
        // crying failure. The sub works; we just can't address it by id.
        return 'external';
    }

    /**
     * Public URL Dynalink POSTs inbound events to. On local dev APP_URL is
     * localhost (unreachable) — set DYNALINK_WEBHOOK_URL to an ngrok/
     * cloudflared tunnel URL so webhooks + auto-replies actually arrive.
     */
    protected function postUrl(): string
    {
        return Settings::get('dynalink.webhook_url', null)
            ?: config('services.dynalink.webhook_url')
            ?: (rtrim(config('app.url'), '/') . '/api/webhooks/dynalink');
    }

    protected function cacheKey(array $s, string $model): string
    {
        return "dynalink:sub:{$s['domain']}:{$s['user']}:{$model}";
    }

    protected function errorKey(array $s): string
    {
        return "dynalink:sub:{$s['domain']}:{$s['user']}:last_error";
    }
}

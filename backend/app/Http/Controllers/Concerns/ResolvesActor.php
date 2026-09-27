<?php

namespace App\Http\Controllers\Concerns;

use App\Models\AuditLog;

use App\Models\Agent;
use App\Models\Tenant;
use App\Models\TenantAdmin;
use App\Services\DynalinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Two-role auth: Admin (Dynalink session, as before) or Agent (local session).
 * Agents inherit their owner's (domain, user) data scope; Dynalink calls for
 * agents run on the shared service credential. Token resolution is lazy —
 * local-only endpoints never touch Dynalink.
 */
trait ResolvesActor
{
    /**
     * Requests that must keep working even when the password has expired —
     * otherwise an expired session could never recover, or even log out.
     */
    protected static function passwordExpiryExempt(Request $r): bool
    {
        $uri  = trim((string) ($r->route()?->uri() ?? ''), '/');
        $path = trim($r->path(), '/');

        $exempt = [
            'api/logout',
            'api/me',
            'api/refresh',
            'api/agent/ping',
            'api/auth/expired-password',
            'api/auth/password-expiry/dismiss',
            'api/auth/verify-password',
        ];

        return in_array($uri, $exempt, true) || in_array($path, $exempt, true);
    }

    /**
     * Day-0 gate for ALREADY-AUTHENTICATED sessions.
     *
     * Login is blocked separately (AuthController). This catches the case the
     * login gate can't: a session that rolls past password_expires_at while
     * the user is signed in. The next authenticated request is refused with
     * 409 + code=password_expired, which the SPA turns into the forced-change
     * screen instead of letting them keep working until they happen to log out.
     */
    protected function assertPasswordNotExpired(Request $r, $user): void
    {
        if (!$user || empty($user->password_expires_at)) return;
        if (!$user->password_expires_at->lte(now())) return;
        if (self::passwordExpiryExempt($r)) return;

        abort(response()->json([
            'code'                => 'password_expired',
            'message'             => 'Your password has expired. Choose a new one to continue.',
            'password_expires_at' => $user->password_expires_at->toJSON(),
        ], 409));
    }

    /** Identity + data scope. 401 when neither session is valid. */
    protected function actor(Request $r): array
    {
        if ($s = $r->session()->get('dynalink')) {
            return [
                'role' => 'admin', 'domain' => $s['domain'], 'user' => $s['user'],
                'token' => $s['access_token'] ?? null,
                'display_name' => $s['display_name'] ?? null,
                'agent_id' => null, 'username' => ($s['user'] ?? '') . '@' . ($s['domain'] ?? ''),
            ];
        }
        if ($p = $r->session()->get('agent_portal')) {
            $identity = \App\Models\AgentIdentity::find($p['id'] ?? null);
            if (!$identity || !$identity->isActive()) {
                $r->session()->forget('agent_portal');
                abort(response()->json(['message' => 'Unauthenticated'], 401));
            }
            return [
                'role' => 'agent', 'domain' => $identity->domain, 'user' => $identity->ext,
                'token' => null,                 // always rides the tenant superadmin token
                'display_name' => $identity->displayName(),   // portal name; falls back to ext
                'agent_id' => null,              // legacy Agent row id; none for portal agents
                'identity_id' => $identity->id,
                'ext' => $identity->ext,
                'username' => $identity->ext . '@' . $identity->domain,
                'portal_auth' => true,
            ];
        }
        if ($a = $r->session()->get('agent')) {
            $agent = Agent::find($a['id'] ?? null);
            if (!$agent || $agent->status !== 'active'
                || (int) $agent->session_version !== (int) ($a['v'] ?? 0)) {
                $r->session()->forget('agent');
                abort(response()->json(['message' => 'Unauthenticated'], 401));
            }
            $this->assertPasswordNotExpired($r, $agent);

            return [
                'role' => 'agent', 'domain' => $agent->domain, 'user' => $agent->user,
                'token' => null, // lazy: dtoken() resolves the service token on demand
                'display_name' => trim($agent->first_name . ' ' . $agent->last_name),
                'agent_id' => $agent->id, 'username' => $agent->username,
                'default_number' => $agent->default_number,
            ];
        }
        if ($t = $r->session()->get('tenant')) {
            $admin = TenantAdmin::with('tenant')->find($t['id'] ?? null);
            if (!$admin || !$admin->isActive() || !$admin->tenant || !$admin->tenant->isActive()
                || (int) $admin->session_version !== (int) ($t['v'] ?? 0)) {
                $r->session()->forget('tenant');
                abort(response()->json(['message' => 'Unauthenticated'], 401));
            }
            $this->assertPasswordNotExpired($r, $admin);

            return [
                'role' => 'admin', 'domain' => $admin->tenant->domain, 'user' => $admin->tenant->dynalink_user,
                'token' => null, // resolved per-request from the tenant credential
                'display_name' => $admin->displayName(),
                'agent_id' => null, 'username' => $admin->username . '@' . $admin->tenant->name,
                'tenant_id' => $admin->tenant_id, 'tenant_admin_id' => $admin->id,
            ];
        }
        abort(response()->json(['message' => 'Unauthenticated'], 401));
    }

    /**
     * Admin gate. Accepts either the Request (resolved here) or an already
     * resolved actor array — several older controllers pass $request.
     */
    protected function requireAdmin(Request|array $actor): void
    {
        if ($actor instanceof Request) $actor = $this->actor($actor);
        abort_unless($actor['role'] === 'admin', 403, 'Admins only.');
    }

    /** MMS media ceiling: carriers reject payloads much over ~1 MB. */
    public const MMS_MAX_BYTES = 1048576;

    /** Abort 422 unless the base64 MMS payload decodes within the cap. */
    protected function assertMediaSize(?string $base64): void
    {
        if ($base64 === null || $base64 === '') return;
        $raw = base64_decode($base64, true);
        if ($raw === false || strlen($raw) > self::MMS_MAX_BYTES) {
            abort(response()->json(
                ['message' => 'Attachment too large — MMS media must be under 1 MB.'], 422));
        }
    }

    /** File-store record IDs are UUIDs — anything else must never reach a path. */
    public static function isUuid(mixed $v): bool
    {
        return is_string($v) && (bool) preg_match('/^[0-9a-f-]{36}$/i', $v);
    }

    /** 404 unless $id is a store-UUID (traversal defense for {id}.json paths). */
    protected function assertUuid(string $id): void
    {
        abort_unless(self::isUuid($id), 404, 'Not found.');
    }

    /**
     * Re-auth for destructive actions: verifies the CURRENT admin's own
     * password — tenant session via local hash, legacy via Dynalink.
     */
    protected function verifyAdminPassword(Request $r, string $password): bool
    {
        if ($t = $r->session()->get('tenant')) {
            $admin = TenantAdmin::find($t['id'] ?? null);
            return (bool) ($admin && $admin->password_hash && Hash::check($password, $admin->password_hash));
        }
        $s = $r->session()->get('dynalink');
        if (!$s) return false;
        try {
            app(DynalinkService::class)->login(
                ($s['user'] ?? '') . '@' . ($s['domain'] ?? ''), $password);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Dynalink bearer for this actor (service credential for agents). */
    protected function dtoken(Request $r): string
    {
        $a = $this->actor($r);
        if ($a['role'] === 'admin') {
            if (!empty($a['token'])) return $a['token']; // legacy Dynalink session
            $tenant = Tenant::find($a['tenant_id'] ?? null);
            abort_unless($tenant && $tenant->isActive(), 503, 'Tenant messaging is unavailable.');
            return $tenant->accessToken();
        }
        // Portal agents ALWAYS ride the tenant superadmin token: their own
        // portal token can only see their own extension, so it could never
        // read or reply on a shared number owned by someone else.
        if (!empty($a['portal_auth'])) {
            $tenant = Tenant::where('domain', $a['domain'])->first();
            abort_unless($tenant && $tenant->isActive(), 503, 'Tenant messaging is unavailable.');
            return $tenant->accessToken();
        }

        // Agents ride their owner's tenant token when one exists…
        $tenant = Tenant::where('domain', $a['domain'])->where('dynalink_user', $a['user'])->first();
        if ($tenant && $tenant->isActive()) {
            try {
                return $tenant->accessToken();
            } catch (\Throwable $e) {
                Log::warning('agent tenant token failed; using service credential', ['tenant' => $tenant->name]);
            }
        }
        $user = \App\Services\Settings::dynalinkServiceCredential('user');
        $pass = \App\Services\Settings::dynalinkServiceCredential('pass');
        abort_unless($user && $pass, 503,
            'Agent access is not configured — set the Dynalink service account in Super → Settings (or DYNALINK_SERVICE_USER/PASS in .env).');
        return Cache::remember(
            "dynalink:service_token:{$a['domain']}:{$a['user']}", 3000,
            fn() => app(DynalinkService::class)->login($user, $pass)['access_token']
        );
    }

    /** One-line audit write with the current actor (admin or agent). Never throws. */
    protected function audit(Request $request, string $action, array $detail = []): void
    {
        $a = $this->actor($request);
        AuditLog::record($a['domain'] ?? null, $a['role'] ?? 'unknown', $a['agent_id'] ?? null,
            $a['display_name'] ?? null, $action, $detail, $request->ip());
    }

    /**
     * Agents may only send/schedule from numbers their admin assigned.
     * Admins pass through. 422 (never a silent override) on violation.
     */
    /**
     * Gate on which from-number an agent may send from.
     *
     * SECURITY: for portal agents this is the ONLY thing standing between an
     * agent and sending as any number on the domain — every call runs on the
     * tenant superadmin token, which can send as anything. It therefore uses
     * AgentAccess's permission sets:
     *
     *   $context = 'reply' → replyableNumbers()  (answer an existing convo)
     *   $context = 'new'   → creatableNumbers()  (start a new conversation)
     *   $context = 'send'  → sendableNumbers()   (legacy union)
     *
     * The read path uses readableNumbers() from the same service, so the
     * sets can never drift apart.
     */
    protected function assertAgentNumber(Request $r, ?string $number, string $context = 'send'): void
    {
        $a = $this->actor($r);
        if ($a['role'] !== 'agent') return;

        $digits = preg_replace('/\D/', '', (string) $number);

        if (!empty($a['portal_auth'])) {
            $access = app(\App\Services\AgentAccess::class);
            $ext = $a['ext'] ?? $a['user'];
            $tok = $this->dtoken($r);
            $allowed = match ($context) {
                'reply' => $access->replyableNumbers($a['domain'], $ext, $tok),
                'new'   => $access->creatableNumbers($a['domain'], $ext, $tok),
                default => $access->sendableNumbers($a['domain'], $ext, $tok),
            };
            if (!$allowed) {
                abort(response()->json(
                    ['message' => 'No SMS numbers available to you — ask your admin.'], 422));
            }
            if (!in_array($digits, $allowed, true)) {
                // Distinguish "can't see it" from "can see it but may not use
                // it this way" (view without reply / create).
                $readable = $access->readableNumbers($a['domain'], $ext, $tok);
                abort(response()->json(['message' => match (true) {
                    !in_array($digits, $readable, true) => 'You can only use your own numbers or numbers your admin gave you access to.',
                    $context === 'reply' => 'You can view this shared number but can\'t reply from it — ask your admin for the Reply permission.',
                    default => 'You can view this shared number but can\'t start new messages from it — ask your admin for the Create New permission.',
                }], 403));
            }
            return;
        }

        $agent = Agent::find($a['agent_id']);
        $allowed = $agent ? $agent->assignedNumbers() : [];
        if (!$allowed) {
            abort(response()->json(['message' => 'No SMS number assigned — ask your admin.'], 422));
        }
        abort_unless(in_array($digits, $allowed, true), response()->json(
            ['message' => 'Choose one of your assigned numbers.'], 422));
    }
}

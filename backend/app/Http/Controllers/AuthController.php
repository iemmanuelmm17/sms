<?php

namespace App\Http\Controllers;

use App\Services\DynalinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\Agent;
use App\Models\Tenant;
use App\Models\TenantAdmin;
use App\Services\Settings;
use App\Models\AuditLog;
use App\Services\LockoutService;
use Illuminate\Support\Facades\Hash;

/**
 * Session-based auth. The Dynalink access_token / refresh_token /
 * user / domain are kept in the Laravel session ("use sessions").
 *
 * The refresh token is ALSO cached server-side (encrypted, 30 days)
 * so the server-to-server webhook can auto-reply without a session.
 *
 * On login we also ensure the `message` + `messagesession` event
 * subscriptions exist so incoming SMS hits our webhook, which then
 * broadcasts over Reverb WebSockets to the React UI instantly.
 */
class AuthController extends Controller
{
    public function __construct(protected DynalinkService $dynalink) {}

    public function login(Request $request)
    {
        // Break-glass Dynalink login: hidden unless the superadmin enables it.
        abort_unless(Settings::legacyLoginEnabled(), 404);
        $data = $request->validate([
            'username' => 'required|string',  // e.g. 6001@1180.DynaCloud
            'password' => 'required|string',
        ]);

        $key = mb_strtolower(trim($data['username']));
        if ($secs = LockoutService::remainingFor($key)) {
            AuditLog::record(explode('@', $key, 2)[1] ?? null, 'unknown', null, $key, 'admin.login.locked', [], $request->ip());
            return response()->json(['message' => 'Too many failed attempts. Try again in ' . gmdate('i:s', $secs) . '.',
                'retry_after_secs' => $secs], 423);
        }
        if (LockoutService::ipLocked($request->ip())) {
            AuditLog::record(explode('@', $key, 2)[1] ?? null, 'unknown', null, $key, 'ip.login-blocked', [], $request->ip());
            return response()->json(['message' => 'Too many failed attempts. Try again in 05:00.',
                'retry_after_secs' => 300], 423);
        }
        try {
            $tokens = $this->dynalink->login($data['username'], $data['password']);
        } catch (\Throwable $e) {
            LockoutService::record($key, $request->ip(), false);
            AuditLog::record(explode('@', $data['username'])[1] ?? null, 'unknown', null, $data['username'],
                'admin.login.fail', [], $request->ip());
            throw $e;
        }
        LockoutService::record($key, $request->ip(), true);
        $request->session()->regenerate(); // fixation defense: fresh ID on login

        $user   = $tokens['user'] ?? explode('@', $data['username'])[0];
        $domain = $tokens['domain'] ?? explode('@', $data['username'])[1] ?? '';

        $request->session()->put('dynalink', [
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'expires_at'    => now()->addSeconds($tokens['expires_in'] ?? 3600)->timestamp,
            'user'          => $user,
            'domain'        => $domain,
            'display_name'  => $tokens['displayName'] ?? $data['username'],
            'email'         => $tokens['user_email'] ?? null,
            'scope'         => $tokens['scope'] ?? null,
        ]);

        // Server-side copy for webhook auto-replies (rotated on refresh).
        if (!empty($tokens['refresh_token'])) {
            Cache::put("dynalink:rt:{$domain}:{$user}", encrypt($tokens['refresh_token']), now()->addDays(30));
        }

        // Ensure webhook subscriptions for realtime inbound SMS.
        app(SubscriptionController::class)->ensureForSession($request);

        AuditLog::record($domain, 'admin', null, $data['username'], 'admin.login.success', ['via' => 'break-glass'], $request->ip());
        return response()->json(['user' => $this->mePayload($request) + ['role' => 'admin']]);
    }

    /**
     * POST /api/tenant/login { username: name@tenantname, password }
     * Tenant-admin local login. The Dynalink token is minted server-side
     * from the tenant's stored credential — eagerly, so bad creds fail here
     * with a clear error instead of breaking every later call.
     */
    public function tenantLogin(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:160',
            'password' => 'required|string',
        ]);
        $key = mb_strtolower(trim($data['username']));
        if ($secs = LockoutService::remainingFor($key)) {
            AuditLog::record(explode('@', $key, 2)[1] ?? null, 'unknown', null, $key, 'tenant.login.locked', [], $request->ip());
            return response()->json(['message' => 'Too many failed attempts. Try again in ' . gmdate('i:s', $secs) . '.',
                'retry_after_secs' => $secs], 423);
        }
        if (LockoutService::ipLocked($request->ip())) {
            AuditLog::record(explode('@', $key, 2)[1] ?? null, 'unknown', null, $key, 'ip.login-blocked', [], $request->ip());
            return response()->json(['message' => 'Too many failed attempts. Try again in 05:00.',
                'retry_after_secs' => 300], 423);
        }
        $parts = explode('@', trim($data['username']), 2);
        $name = isset($parts[0]) ? mb_strtolower(trim($parts[0])) : '';
        $tenantName = isset($parts[1]) ? mb_strtolower(trim($parts[1])) : '';
        $tenant = ($name !== '' && $tenantName !== '' && preg_match('/^[a-z0-9]+$/', $name))
            ? Tenant::where('name', $tenantName)->first() : null;
        if ($tenant && !$tenant->isActive()) {
            AuditLog::record($tenant->domain, 'unknown', null, $key, 'tenant.login.deactivated', [], $request->ip());
            return response()->json(['message' => 'This tenant has been deactivated. Contact support for help.'], 403);
        }
        $admin = $tenant
            ? TenantAdmin::where('tenant_id', $tenant->id)->where('username', $name)->first() : null;
        if (!$admin || !$admin->isActive() || !$admin->password_hash
            || !Hash::check($data['password'], $admin->password_hash)) {
            LockoutService::record($key, $request->ip(), false);
            AuditLog::record($tenant?->domain, 'unknown', null, $key, 'tenant.login.fail', [], $request->ip());
            return response()->json(['message' => 'Incorrect username or password.'], 401);
        }
        try {
            $tenant->accessToken();
        } catch (\Throwable $e) {
            AuditLog::record($tenant->domain, 'unknown', null, $key, 'tenant.login.token-fail', [], $request->ip());
            return response()->json(['message' => 'Messaging service unavailable — try again shortly.'], 503);
        }
        $request->session()->regenerate();
        $request->session()->put('tenant', ['id' => $admin->id, 'v' => $admin->session_version]);
        $admin->forceFill(['last_seen_at' => now()])->save();
        LockoutService::record($key, $request->ip(), true);
        try {
            app(SubscriptionController::class)->ensureForSession($request);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('tenant login: subscription ensure failed: ' . $e->getMessage());
        }
        AuditLog::record($tenant->domain, 'admin', null, $admin->displayName(), 'tenant.login.success', [], $request->ip());
        return response()->json(['user' => $this->tenantAdminPayload($admin->fresh())]);
    }

    /** GET /api/auth/login-options — which login forms the portal renders. */
    public function loginOptions()
    {
        return response()->json([
            'tenant' => true,
            'legacy_dynalink' => Settings::legacyLoginEnabled(),
        ]);
    }

    protected function tenantAdminPayload(TenantAdmin $admin): array
    {
        $admin->loadMissing('tenant');
        return [
            'role' => 'admin',
            'id' => $admin->id,
            'username' => $admin->username . '@' . ($admin->tenant->name ?? ''),
            'user' => $admin->tenant->dynalink_user ?? null,
            'domain' => $admin->tenant->domain ?? null,
            'display_name' => $admin->displayName(),
            'first_name' => $admin->first_name, 'last_name' => $admin->last_name,
            'tenant_id' => $admin->tenant_id,
            'main_number' => $admin->tenant->main_number ?? null,
            'tenant' => $admin->tenant->name ?? null,
            'company' => $admin->tenant->company_name ?? null,
        ];
    }

    public function me(Request $request)
    {
        if ($s = $request->session()->get('dynalink')) {
            return response()->json(['user' => $this->mePayload($request) + ['role' => 'admin']]);
        }
        if ($t = $request->session()->get('tenant')) {
            $admin = TenantAdmin::with('tenant')->find($t['id'] ?? null);
            if (!$admin || !$admin->isActive() || !$admin->tenant || !$admin->tenant->isActive()
                || (int) $admin->session_version !== (int) ($t['v'] ?? 0)) {
                $request->session()->forget('tenant');
                return response()->json(['message' => 'Unauthenticated'], 401);
            }
            return response()->json(['user' => $this->tenantAdminPayload($admin)]);
        }
        if ($a = $request->session()->get('agent')) {
            $agent = Agent::find($a['id'] ?? null);
            if (!$agent || $agent->status !== 'active'
                || (int) $agent->session_version !== (int) ($a['v'] ?? 0)) {
                $request->session()->forget('agent');
                return response()->json(['message' => 'Unauthenticated'], 401);
            }
            return response()->json(['user' => $this->agentPayload($agent)]);
        }
        return response()->json(['message' => 'Unauthenticated'], 401);
    }

    /** POST /api/agent/login { username: name@tenant, password } */
    public function agentLogin(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:160',
            'password' => 'required|string',
        ]);
        $key = mb_strtolower(trim($data['username']));
        if ($secs = LockoutService::remainingFor($key)) {
            AuditLog::record(explode('@', $key, 2)[1] ?? null, 'unknown', null, $key, 'agent.login.locked', [], $request->ip());
            return response()->json(['message' => 'Too many failed attempts. Try again in ' . gmdate('i:s', $secs) . '.',
                'retry_after_secs' => $secs], 423);
        }
        if (LockoutService::ipLocked($request->ip())) {
            AuditLog::record(explode('@', $key, 2)[1] ?? null, 'unknown', null, $key, 'ip.login-blocked', [], $request->ip());
            return response()->json(['message' => 'Too many failed attempts. Try again in 05:00.',
                'retry_after_secs' => 300], 423);
        }
        $parts = explode('@', trim($data['username']), 2);
        $name = isset($parts[0]) ? mb_strtolower(trim($parts[0])) : '';
        $suffix = isset($parts[1]) ? trim($parts[1]) : '';
        // Accept username@tenantname as well as legacy username@domain.
        $asTenant = ($suffix !== '' && preg_match('/^[a-z0-9-]+$/i', $suffix))
            ? Tenant::where('name', mb_strtolower($suffix))->first() : null;
        if ($asTenant && !$asTenant->isActive()) {
            AuditLog::record($asTenant->domain, 'unknown', null, $key, 'agent.login.deactivated', [], $request->ip());
            return response()->json(['message' => 'This tenant has been deactivated. Contact support for help.'], 403);
        }
        $domain = $asTenant ? $asTenant->domain : $suffix;
        $agent = ($name !== '' && $domain !== '' && preg_match('/^[a-z0-9]+$/', $name))
            ? Agent::where('domain', $domain)->where('username', $name)->first()
            : null;
        // A deactivated tenant locks its agents out too (fail-open when unmapped).
        if ($agent && ($owner = Tenant::where('domain', $agent->domain)->where('dynalink_user', $agent->user)->first())
            && !$owner->isActive()) {
            AuditLog::record($agent->domain, 'unknown', $agent->id, $key, 'agent.login.deactivated', [], $request->ip());
            return response()->json(['message' => 'This tenant has been deactivated. Contact support for help.'], 403);
        }
        if (!$agent || $agent->status !== 'active' || !$agent->password_hash
            || !Hash::check($data['password'], $agent->password_hash)) {
            LockoutService::record($key, $request->ip(), false);
            AuditLog::record($domain ?: null, 'unknown', $agent?->id, $key,
                'agent.login.fail', [], $request->ip());
            return response()->json(['message' => 'Incorrect username or password.'], 401);
        }
        $request->session()->regenerate();
        $request->session()->put('agent', ['id' => $agent->id, 'v' => $agent->session_version]);
        $agent->forceFill(['last_seen_at' => now()])->save();
        LockoutService::record($key, $request->ip(), true);
        AuditLog::record($domain, 'agent', $agent->id,
            trim($agent->first_name . ' ' . $agent->last_name), 'agent.login.success', [], $request->ip());
        return response()->json(['user' => $this->agentPayload($agent->fresh())]);
    }

    protected function agentPayload(Agent $agent): array
    {
        return [
            'role' => 'agent', 'id' => $agent->id,
            'username' => $agent->username . '@' . $agent->domain,
            'user' => $agent->username, 'domain' => $agent->domain,
            'scope_user' => $agent->user, // owner's Dynalink user: the realtime channel scope
            'display_name' => trim($agent->first_name . ' ' . $agent->last_name),
            'first_name' => $agent->first_name, 'last_name' => $agent->last_name,
            'color' => $agent->tag_color, 'status' => $agent->status,
            'default_number' => $agent->default_number,
            'assigned_numbers' => $agent->assignedNumbers(),
        ];
    }

    /** POST /api/auth/verify-password — re-check the login password for
     * destructive confirms. Never touches the session. */
    public function verifyPassword(Request $request)
    {
        $data = $request->validate(['password' => 'required|string']);
        if ($t = $request->session()->get('tenant')) {
            $admin = TenantAdmin::find($t['id'] ?? null);
            if (!$admin || !$admin->password_hash || !Hash::check($data['password'], $admin->password_hash)) {
                return response()->json(['message' => 'Incorrect password.'], 403);
            }
            return response()->json(['ok' => true]);
        }
        $s = $request->session()->get('dynalink');
        if (!$s) return response()->json(['message' => 'Unauthenticated'], 401);
        try {
            $this->dynalink->login(($s['user'] ?? '') . '@' . ($s['domain'] ?? ''), $data['password']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Incorrect password.'], 403);
        }
        return response()->json(['ok' => true]);
    }

    public function logout(Request $request)
    {
        $dl = $request->session()->get('dynalink');
        $agSess = $request->session()->get('agent');
        if ($dl) AuditLog::record($dl['domain'] ?? null, 'admin', null, ($dl['user'] ?? '') . '@' . ($dl['domain'] ?? ''), 'admin.logout', [], $request->ip());
        if (!empty($agSess['id']) && ($ag = Agent::find($agSess['id']))) {
            AuditLog::record($ag->domain, 'agent', $ag->id, trim($ag->first_name . ' ' . $ag->last_name), 'agent.logout', [], $request->ip());
        }
        if (($tSess = $request->session()->get('tenant')) && ($ta = TenantAdmin::find($tSess['id'] ?? null))) {
            AuditLog::record($ta->tenant->domain ?? null, 'admin', null, $ta->displayName(), 'tenant.logout', [], $request->ip());
        }
        $request->session()->forget(['dynalink', 'agent', 'tenant']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['ok' => true]);
    }

    /** Refresh Dynalink token + renew event subscriptions together. */
    public function refresh(Request $request)
    {
        $s = $request->session()->get('dynalink');
        if (!$s || empty($s['refresh_token'])) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        try {
            $tokens = $this->dynalink->refreshToken($s['refresh_token']);
        } catch (\Throwable $e) {
            // Dead refresh token → the app must log out, not 500.
            $request->session()->forget('dynalink');
            return response()->json(['message' => 'Session expired'], 401);
        }

        $s['access_token']  = $tokens['access_token'];
        $s['refresh_token'] = $tokens['refresh_token'] ?? $s['refresh_token'];
        $s['expires_at']    = now()->addSeconds($tokens['expires_in'] ?? 3600)->timestamp;
        $request->session()->put('dynalink', $s);

        if (!empty($tokens['refresh_token'])) {
            Cache::put("dynalink:rt:{$s['domain']}:{$s['user']}", encrypt($tokens['refresh_token']), now()->addDays(30));
        }

        // Renew subscription expiry in the same pass (per notes).
        try {
            app(SubscriptionController::class)->ensureForSession($request);
        } catch (\Throwable $e) {
            // Non-fatal: the token is fresh, subs retry on next login.
            \Illuminate\Support\Facades\Log::warning('refresh: subscription renew failed: ' . $e->getMessage());
        }

        return response()->json(['ok' => true, 'expires_at' => $s['expires_at']]);
    }

    protected function mePayload(Request $request): array
    {
        $s = $request->session()->get('dynalink', []);
        return [
            'username'     => ($s['user'] ?? '') . '@' . ($s['domain'] ?? ''),
            'user'         => $s['user'] ?? null,
            'domain'       => $s['domain'] ?? null,
            'display_name' => $s['display_name'] ?? null,
            'email'        => $s['email'] ?? null,
            'scope'        => $s['scope'] ?? null,
            'expires_at'    => $s['expires_at'] ?? null,
            'main_number'   => Tenant::where('domain', $s['domain'] ?? '')->where('dynalink_user', $s['user'] ?? '')->value('main_number'),
        ];
    }
}

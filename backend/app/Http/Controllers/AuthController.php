<?php

namespace App\Http\Controllers;

use App\Services\DynalinkService;
use App\Services\OnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\Agent;
use App\Models\Tenant;
use App\Models\TenantAdmin;
use App\Services\Settings;
use App\Services\BroadcastScope;
use App\Events\DataChanged;
use App\Models\AuditLog;
use App\Services\LockoutService;
use App\Models\PasswordHistory;
use App\Rules\PasswordPolicy;
use App\Services\PasswordPolicyService;
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
        // Day 0: credentials were correct, but the password lapsed. Park the
        // verified identity in the session and force a change instead of
        // completing the login.
        if ($admin->isPasswordExpired()) {
            $request->session()->regenerate();
            $request->session()->put('password_reset_required', [
                'type' => PasswordHistory::TYPE_ADMIN,
                'id'   => $admin->id,
                'at'   => now()->timestamp,
            ]);
            AuditLog::record($tenant->domain, 'admin', $admin->id, $admin->displayName(),
                'tenant.login.password-expired', [], $request->ip());
            return response()->json([
                'code'                => 'password_expired',
                'message'             => 'Your password has expired. Choose a new one to continue.',
                'password_expires_at' => $admin->password_expires_at?->toJSON(),
            ], 409);
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
            'scope_user' => BroadcastScope::ROOM, // shared realtime room for the domain
            'display_name' => $admin->displayName(),
            'first_name' => $admin->first_name, 'last_name' => $admin->last_name,
            'tenant_id' => $admin->tenant_id,
            'main_number' => $admin->tenant->main_number ?? null,
            'tenant' => $admin->tenant->name ?? null,
            'company' => $admin->tenant->company_name ?? null,
            'onboarding' => OnboardingService::state($admin),
        ] + $admin->passwordExpiryState();
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
        if ($p = $request->session()->get('agent_portal')) {
            $identity = \App\Models\AgentIdentity::find($p['id'] ?? null);
            if (!$identity || !$identity->isActive()) {
                // Covers the kill switch: disabling an agent ends their session
                // on the next request without needing a version counter.
                $request->session()->forget('agent_portal');
                return response()->json(['message' => 'Unauthenticated'], 401);
            }
            return response()->json(['user' => $this->agentIdentityPayload($identity)]);
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
    /**
     * POST /api/agent/login — agent sign-in via the Dynalink portal.
     *
     * The portal owns authentication: we verify the credential against
     * {authBase}/tokens, then DISCARD the returned token. The agent's own
     * token can only see their own extension, so it cannot read a shared
     * number owned by someone else — every later API call therefore uses the
     * tenant superadmin token, with permissions enforced by AgentAccess.
     *
     * No local password is ever stored, checked, expired or reset.
     */
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

        // ext@domain, or ext@tenantname as a convenience.
        $parts  = explode('@', trim($data['username']), 2);
        $ext    = \App\Models\AgentIdentity::normalizeExt($parts[0] ?? '');
        $suffix = trim($parts[1] ?? '');
        if ($ext === '' || $suffix === '') {
            return response()->json(['message' => 'Sign in with your portal extension, e.g. 6001@yourdomain.'], 422);
        }

        $asTenant = preg_match('/^[a-z0-9-]+$/i', $suffix)
            ? Tenant::where('name', mb_strtolower($suffix))->first() : null;
        $domain = $asTenant ? $asTenant->domain : $suffix;
        $tenant = $asTenant ?: Tenant::where('domain', $domain)->first();

        // The app only serves domains we host, and only while active.
        if (!$tenant) {
            LockoutService::record($key, $request->ip(), false);
            AuditLog::record($domain ?: null, 'unknown', null, $key, 'agent.login.unknown-domain', [], $request->ip());
            return response()->json(['message' => 'Incorrect username or password.'], 401);
        }
        if (!$tenant->isActive()) {
            AuditLog::record($tenant->domain, 'unknown', null, $key, 'agent.login.deactivated', [], $request->ip());
            return response()->json(['message' => 'This tenant has been deactivated. Contact support for help.'], 403);
        }

        // Verify against the portal, then throw the token away.
        try {
            $this->dynalink->login($ext . '@' . $tenant->domain, $data['password']);
        } catch (\Throwable $e) {
            // Optional transition path: a local agent account may still be
            // honoured while LEGACY_AGENT_LOGIN=true. Off by default.
            if (config('services.dynalink.legacy_agent_login')
                && ($legacy = $this->tryLegacyAgentLogin($request, $tenant, $key, $data))) {
                return $legacy;
            }
            LockoutService::record($key, $request->ip(), false);
            AuditLog::record($tenant->domain, 'unknown', null, $key, 'agent.login.fail', [], $request->ip());
            return response()->json(['message' => 'Incorrect username or password.'], 401);
        }

        // Auto-provision on first sign-in; admins can disable afterwards.
        $identity = \App\Models\AgentIdentity::firstOrNew([
            'domain' => $tenant->domain, 'ext' => $ext,
        ]);
        $isNew = !$identity->exists;
        if ($isNew) {
            $identity->fill([
                'display_name'   => $ext,
                'status'         => 'active',
                'onboarding'     => [],
                'first_login_at' => now(),
            ]);
        }
        if (!$identity->isActive()) {
            AuditLog::record($tenant->domain, 'agent', $identity->id, $ext,
                'agent.login.disabled', [], $request->ip());
            return response()->json(['message' => 'Access disabled — contact your admin.'], 403);
        }
        $identity->last_seen_at = now();
        $identity->save();

        // Pull the real name from the portal on first sign-in (and whenever we
        // still have none) so signatures and the roster show "Peter Thompson"
        // rather than "6001". Never blocks login if the portal is unreachable.
        if ($isNew || $identity->first_name === null) {
            try { $identity->syncProfile($tenant->accessToken()); }
            catch (\Throwable $e) {
                Log::warning('agent profile sync failed at login',
                    ['ext' => $ext, 'error' => $e->getMessage()]);
            }
        }

        $request->session()->regenerate();
        $request->session()->put('agent_portal', [
            'id' => $identity->id, 'domain' => $tenant->domain, 'ext' => $ext,
        ]);
        LockoutService::record($key, $request->ip(), true);
        AuditLog::record($tenant->domain, 'agent', $identity->id, $ext,
            $isNew ? 'agent.login.provisioned' : 'agent.login.success', [], $request->ip());

        // A first sign-in adds a row to the admins' Users roster — announce it
        // on the domain room so open Users pages show the new user instantly.
        // Only on provisioning: ordinary logins just touch last_seen_at, which
        // is not worth a roster refetch on every tab.
        if ($isNew) {
            DataChanged::send($tenant->domain, $ext, 'agents', 'saved', $identity->id, [
                'ext' => $ext, 'provisioned' => true,
            ]);
        }

        return response()->json(['user' => $this->agentIdentityPayload($identity, $tenant)]);
    }

    /**
     * Legacy local-password agent login, used only while
     * LEGACY_AGENT_LOGIN=true and only after the portal has rejected the
     * credential. Returns null when it does not apply, so the caller falls
     * through to the normal 401.
     *
     * The agents table is deliberately still here: conversation_meta.agent_id
     * and sent_message_log.agent_id reference it, so removing it is a data
     * migration rather than a code deletion.
     */
    protected function tryLegacyAgentLogin(Request $request, Tenant $tenant, string $key, array $data)
    {
        $name = mb_strtolower(explode('@', trim($data['username']), 2)[0] ?? '');
        if ($name === '' || !preg_match('/^[a-z0-9]+$/', $name)) return null;

        $agent = Agent::where('domain', $tenant->domain)->where('username', $name)->first();
        if (!$agent || $agent->status !== 'active' || !$agent->password_hash
            || !Hash::check($data['password'], $agent->password_hash)) {
            return null;
        }
        if ($agent->isPasswordExpired()) {
            $request->session()->regenerate();
            $request->session()->put('password_reset_required', [
                'type' => PasswordHistory::TYPE_AGENT, 'id' => $agent->id, 'at' => now()->timestamp,
            ]);
            return response()->json([
                'code' => 'password_expired',
                'message' => 'Your password has expired. Choose a new one to continue.',
                'password_expires_at' => $agent->password_expires_at?->toJSON(),
            ], 409);
        }

        $request->session()->regenerate();
        $request->session()->put('agent', ['id' => $agent->id, 'v' => $agent->session_version]);
        $agent->forceFill(['last_seen_at' => now()])->save();
        LockoutService::record($key, $request->ip(), true);
        AuditLog::record($tenant->domain, 'agent', $agent->id,
            trim($agent->first_name . ' ' . $agent->last_name),
            'agent.login.legacy', [], $request->ip());
        return response()->json(['user' => $this->agentPayload($agent->fresh())]);
    }

    /** Session payload for a portal-authenticated agent. */
    protected function agentIdentityPayload(\App\Models\AgentIdentity $i, ?Tenant $tenant = null): array
    {
        $tenant = $tenant ?: Tenant::where('domain', $i->domain)->first();
        $visible = [];    // sendable: own + reply + create grants
        $readable = [];   // viewable: own + view grants
        $replyable = [];  // own + reply grants (answer existing conversations)
        $creatable = [];  // own + create grants (start new conversations)
        $own = [];        // numbers the portal provisions to THIS extension
        try {
            $access = app(\App\Services\AgentAccess::class);
            $tok = $tenant?->accessToken();
            $owners = [];
            try { $owners = app(\App\Services\DynalinkService::class)->numberOwners((string) $tok, $i->domain); }
            catch (\Throwable $e) { /* provider hiccups: own list stays empty */ }
            $visible   = $access->sendableNumbers($i->domain, $i->ext, $tok, $owners);
            $readable  = $access->readableNumbers($i->domain, $i->ext, $tok, $owners);
            $replyable = $access->replyableNumbers($i->domain, $i->ext, $tok, $owners);
            $creatable = $access->creatableNumbers($i->domain, $i->ext, $tok, $owners);
            $own = $access->ownNumbers($owners, $i->ext);
        } catch (\Throwable $e) {
            Log::warning('agent payload: number sets failed', ['ext' => $i->ext, 'error' => $e->getMessage()]);
        }
        return [
            'role' => 'agent', 'id' => $i->id,
            'username' => $i->ext . '@' . $i->domain,
            'user' => $i->ext, 'domain' => $i->domain,
            'ext' => $i->ext,
            // Realtime channel: the domain's SHARED room, not this extension.
            // Every participant in the domain (admin + all agents, each on a
            // different user extension) listens on the same room.
            'scope_user' => BroadcastScope::ROOM,
            'display_name' => $i->displayName(),
            'first_name' => $i->first_name,
            'last_name' => $i->last_name,
            'email' => $i->email,
            'color' => $i->tag_color, 'status' => $i->status,
            // assigned_numbers keeps its name for compatibility, but now means
            // "may send from" (reply ∪ create). The finer sets:
            //   own_numbers        — portal-provisioned to this extension
            //   readable_numbers   — may see the inbox (view)
            //   replyable_numbers  — may answer existing conversations
            //   creatable_numbers  — may start new conversations
            'assigned_numbers' => $visible,
            'readable_numbers' => $readable,
            'replyable_numbers' => $replyable,
            'creatable_numbers' => $creatable,
            'own_numbers' => $own,
            'main_number' => $tenant?->main_number,
            'onboarding' => \App\Services\OnboardingService::state($i),
            'portal_auth' => true,   // no local password: hide change-password UI
        ];
    }

    protected function agentPayload(Agent $agent): array
    {
        return [
            'role' => 'agent', 'id' => $agent->id,
            'username' => $agent->username . '@' . $agent->domain,
            'user' => $agent->username, 'domain' => $agent->domain,
            // Realtime channel: the domain's shared room (see BroadcastScope).
            'scope_user' => BroadcastScope::ROOM,
            'display_name' => trim($agent->first_name . ' ' . $agent->last_name),
            'first_name' => $agent->first_name, 'last_name' => $agent->last_name,
            'color' => $agent->tag_color, 'status' => $agent->status,
            'default_number' => $agent->default_number,
            'assigned_numbers' => $agent->assignedNumbers(),
            'onboarding' => OnboardingService::state($agent),
        ] + $agent->passwordExpiryState();
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
        if ($pSess = $request->session()->get('agent_portal')) {
            AuditLog::record($pSess['domain'] ?? null, 'agent', $pSess['id'] ?? null,
                $pSess['ext'] ?? '', 'agent.logout', [], $request->ip());
        }
        $request->session()->forget(['dynalink', 'agent', 'tenant', 'agent_portal']);
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

    /**
     * POST /api/auth/expired-password { new_password, confirm_password }
     *
     * Completes a login that was interrupted at day 0. Identity was already
     * proven by the login attempt seconds earlier, so no current-password
     * field is required — the short-lived session flag is what authorises
     * this. Same complexity + reuse rules as every other password path.
     */
    public function expiredPassword(Request $request)
    {
        $data = $request->validate([
            'new_password'     => ['required', 'string', new PasswordPolicy()],
            'confirm_password' => 'required|string',
        ]);

        if ($data['new_password'] !== $data['confirm_password']) {
            return response()->json(['message' => 'Passwords do not match.'], 422);
        }

        $flag = $request->session()->get('password_reset_required');
        if (!is_array($flag) || empty($flag['id']) || empty($flag['at'])
            || (now()->timestamp - (int) $flag['at']) > PasswordPolicyService::RESET_FLAG_TTL) {
            $request->session()->forget('password_reset_required');
            return response()->json(['message' => 'That reset link expired. Sign in again.'], 422);
        }

        $type = ($flag['type'] ?? PasswordHistory::TYPE_AGENT) === PasswordHistory::TYPE_ADMIN
            ? PasswordHistory::TYPE_ADMIN
            : PasswordHistory::TYPE_AGENT;

        $user = $type === PasswordHistory::TYPE_ADMIN
            ? TenantAdmin::with('tenant')->find($flag['id'])
            : Agent::find($flag['id']);

        if (!$user || (isset($user->status) && $user->status !== 'active')) {
            $request->session()->forget('password_reset_required');
            return response()->json(['message' => 'That account is no longer active.'], 422);
        }

        if ($err = PasswordPolicyService::reuseError($user, $type, $data['new_password'])) {
            return response()->json(['message' => $err], 422);
        }

        $isAdmin = $type === PasswordHistory::TYPE_ADMIN;
        $domain  = $isAdmin ? ($user->tenant->domain ?? null) : $user->domain;

        PasswordPolicyService::change($user, $type, $data['new_password'],
            PasswordPolicyService::T_FORCED, [
                'domain'     => $domain,
                'actor_type' => $isAdmin ? 'admin' : 'agent',
                'actor_id'   => $user->getKey(),
                'actor_name' => PasswordPolicyService::displayNameFor($user),
                'ip'         => $request->ip(),
            ]);

        $request->session()->forget('password_reset_required');

        // Finish the interrupted login: fresh cycle, ordinary session.
        $request->session()->regenerate();
        $request->session()->put(
            $isAdmin ? 'tenant' : 'agent',
            ['id' => $user->id, 'v' => $user->session_version]
        );
        $user->forceFill(['last_seen_at' => now()])->save();

        try {
            app(SubscriptionController::class)->ensureForSession($request);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('expired-password: subscription ensure failed: ' . $e->getMessage());
        }

        return response()->json([
            'user' => $isAdmin
                ? $this->tenantAdminPayload($user->fresh())
                : $this->agentPayload($user->fresh()),
        ]);
    }

    /**
     * POST /api/auth/password-expiry/dismiss — "Don't notify again".
     *
     * Scoped to the exact password_expires_at the user is looking at. The
     * next password change writes a NEW expires_at, which makes this
     * dismissal stale all by itself — no cleanup job needed.
     */
    public function dismissExpiryNotice(Request $request)
    {
        $user = null;
        if ($a = $request->session()->get('agent')) {
            $user = Agent::find($a['id'] ?? null);
        } elseif ($t = $request->session()->get('tenant')) {
            $user = TenantAdmin::find($t['id'] ?? null);
        }

        if (!$user || !$user->password_expires_at) {
            return response()->json(['ok' => true]);
        }

        $user->forceFill([
            'password_expiry_notice_dismissed_for' => $user->password_expires_at,
        ])->save();

        return response()->json(['ok' => true]);
    }

    protected function mePayload(Request $request): array
    {
        $s = $request->session()->get('dynalink', []);
        return [
            'username'     => ($s['user'] ?? '') . '@' . ($s['domain'] ?? ''),
            'user'         => $s['user'] ?? null,
            'domain'       => $s['domain'] ?? null,
            // Shared realtime room for the domain (see BroadcastScope).
            'scope_user'   => BroadcastScope::ROOM,
            'display_name' => $s['display_name'] ?? null,
            'email'        => $s['email'] ?? null,
            'scope'        => $s['scope'] ?? null,
            'expires_at'    => $s['expires_at'] ?? null,
            'main_number'   => Tenant::where('domain', $s['domain'] ?? '')->where('dynalink_user', $s['user'] ?? '')->value('main_number'),
        ];
    }
}

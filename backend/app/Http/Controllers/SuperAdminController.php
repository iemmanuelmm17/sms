<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureSuperAdminIp;
use App\Models\Agent;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\SuperAdminAllowedIp;
use App\Models\WebhookAllowedIp;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantAdmin;
use App\Models\AutoReply;
use App\Models\AutoReplyLog;
use App\Models\ConversationMeta;
use App\Models\LoginAttempt;
use App\Models\Integration;
use App\Models\IntegrationNumber;
use App\Models\IntegrationSession;
use App\Models\OptEvent;
use App\Models\PasswordResetRequest;
use App\Models\PasswordHistory;
use App\Services\PasswordPolicyService;
use App\Models\ScheduledMessage;
use App\Models\Template;
use App\Models\TenantPasswordResetRequest;
use App\Models\WebhookEvent;
use App\Services\DynalinkService;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Superadmin portal API: tenants, tenant admins, global settings, audit.
 * Deletes are intentionally absent (Phase 3 ships them with 2-step confirm).
 */
class SuperAdminController extends Controller
{
    protected function sa(Request $r): SuperAdmin
    {
        return $r->attributes->get('superadmin');
    }

    // ---------------- Tenants ----------------

    public function tenantsIndex(Request $request)
    {
        $tenants = Tenant::withCount('admins')->orderBy('name')->get();
        $counts = Agent::selectRaw('domain, `user`, COUNT(*) AS c')
            ->groupBy('domain', 'user')->get()
            ->keyBy(fn($r) => $r->domain . '|' . $r->user);
        return response()->json($tenants->map(fn($t) => array_merge($t->toArray(), [
            'agents_count' => (int) ($counts[$t->domain . '|' . $t->dynalink_user]->c ?? 0),
        ])));
    }

    /**
     * POST /api/superadmin/tenants/verify-numbers — step 1 of tenant
     * creation: validate the pending identity, log into Dynalink with
     * it, and return the account's assigned SMS numbers. 422 when the
     * account has none (a tenant cannot be created without one).
     */
    public function tenantsVerifyNumbers(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]{2,60}$/', 'unique:tenants,name'],
            'domain' => 'required|string|max:190',
            'dynalink_user' => 'required|string|max:190',
            'dynalink_pass' => 'required|string',
        ]);
        if (Tenant::where('domain', $data['domain'])->where('dynalink_user', $data['dynalink_user'])->exists()) {
            return response()->json(['message' => 'That Dynalink identity is already assigned to a tenant.'], 422);
        }
        try {
            $numbers = $this->fetchAssignedNumbers($data['dynalink_user'], $data['domain'], $data['dynalink_pass']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if ($numbers === []) {
            return response()->json(['message' => 'No SMS numbers were found on this Dynalink domain. A tenant cannot be created without one.'], 422);
        }
        return response()->json(['numbers' => $numbers]);
    }

    /**
     * POST /api/superadmin/tenants/{tenant}/numbers — assigned SMS
     * numbers via the tenant's stored credential (powers the legacy
     * one-time main-number picker).
     */
    public function tenantsNumbers(Request $request, Tenant $tenant)
    {
        try {
            $numbers = $this->fetchAssignedNumbers(
                $tenant->dynalink_user, $tenant->domain, $tenant->dynalink_pass);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['numbers' => $numbers]);
    }

    /**
     * Live Dynalink login + DOMAIN-WIDE SMS-number fetch for a (possibly
     * not-yet-saved) credential. Returns [{number, digits, dest}]. Throws
     * RuntimeException with a user-safe message on any failure.
     *
     *   GET {authBase}/domains/{domain}/smsnumbers
     *
     * The registered Dynalink account is a super-user domain login with access
     * to every SMS number on the domain, regardless of which extension a
     * number is assigned to. So "does this tenant have SMS?" is a question
     * about the DOMAIN's inventory, not about the login's own extension —
     * checking the latter would wrongly reject most valid accounts.
     *
     * Named fetchAssignedNumbers for historical reasons; it is domain-wide.
     */
    protected function fetchAssignedNumbers(string $user, string $domain, string $pass): array
    {
        try {
            $tokens = app(DynalinkService::class)->login($user . '@' . $domain, $pass);
            $token = (string) ($tokens['access_token'] ?? '');
            if ($token === '') throw new \RuntimeException('Dynalink login failed — check username, domain, and password.');
            $raw = app(DynalinkService::class)->domainSmsNumbers($token, $domain);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $resp = $e instanceof \Illuminate\Http\Exceptions\HttpResponseException ? $e->getResponse() : null;
            if ($resp && $resp->getStatusCode() === 401) {
                throw new \RuntimeException('Dynalink login failed — check username, domain, and password.');
            }
            throw new \RuntimeException('Could not reach Dynalink with that credential — check it and try again.');
        }
        $out = [];
        foreach ((array) $raw as $n) {
            $num = (string) (is_array($n) ? ($n['number'] ?? '') : $n);
            $d = preg_replace('/\D/', '', $num);
            if (strlen($d) >= 7 && strlen($d) <= 15) {
                $out[] = [
                    'number' => $num,
                    'digits' => $d,
                    // Owning extension: this is the NS-API $user for that
                    // number's message sessions.
                    'dest'   => is_array($n) && isset($n['dest']) ? (string) $n['dest'] : null,
                ];
            }
        }
        // De-dupe by digits, keep provider order.
        $seen = [];
        return array_values(array_filter($out, function ($r) use (&$seen) {
            if (isset($seen[$r['digits']])) return false;
            $seen[$r['digits']] = true;
            return true;
        }));
    }

    public function tenantsStore(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]{2,60}$/', 'unique:tenants,name'],
            'domain' => 'required|string|max:190',
            'dynalink_user' => 'required|string|max:190',
            'dynalink_pass' => 'required|string',
            'company_name' => 'sometimes|nullable|string|max:190',
            'main_number' => 'required|string|max:32',
            'admin' => 'sometimes|array',
        ]);
        if (Tenant::where('domain', $data['domain'])->where('dynalink_user', $data['dynalink_user'])->exists()) {
            return response()->json(['message' => 'That Dynalink identity is already assigned to a tenant.'], 422);
        }
        $adminData = null;
        if (!empty($data['admin'])) {
            $adminData = validator($data['admin'], [
                'username' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+$/i'],
                'first_name' => 'required|string|max:60',
                'last_name' => 'required|string|max:60',
                'password' => 'required|string|min:8|max:200',
                'secret_question' => 'required|string|max:200',
                'secret_answer' => 'required|string|max:200',
            ])->validate();
        }
        // Server-side re-verification: never trust the client's numbers.
        try {
            $numbers = $this->fetchAssignedNumbers($data['dynalink_user'], $data['domain'], $data['dynalink_pass']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if ($numbers === []) {
            return response()->json(['message' => 'No SMS numbers were found on this Dynalink domain. A tenant cannot be created without one.'], 422);
        }
        $main = preg_replace('/\D/', '', (string) $data['main_number']);
        if (!in_array($main, array_column($numbers, 'digits'), true)) {
            return response()->json(["message" => "Main number must be one of the SMS numbers on this domain."], 422);
        }
        $tenant = DB::transaction(function () use ($data, $adminData, $main, $request) {
            $t = Tenant::create([
                'name' => mb_strtolower($data['name']), 'domain' => $data['domain'],
                'dynalink_user' => $data['dynalink_user'], 'dynalink_pass' => $data['dynalink_pass'],
                'main_number' => $main,
                'company_name' => $data['company_name'] ?? null, 'status' => 'active',
            ]);
            if ($adminData) {
                $admin = TenantAdmin::create([
                    'tenant_id' => $t->id, 'username' => mb_strtolower($adminData['username']),
                    'first_name' => $adminData['first_name'], 'last_name' => $adminData['last_name'],
                    'password_hash' => Hash::make($adminData['password']),
                    'secret_question' => trim($adminData['secret_question']),
                    'secret_answer_hash' => Hash::make(AgentController::normalizeAnswer($adminData['secret_answer'])),
                    'status' => 'active',
                ]);
                PasswordPolicyService::startCycle($admin, PasswordHistory::TYPE_ADMIN,
                    PasswordPolicyService::T_ADMIN, [
                        'domain'     => $t->domain,
                        'actor_type' => 'superadmin',
                        'actor_id'   => null,
                        'actor_name' => $this->sa($request)->username ?? 'superadmin',
                        'ip'         => $request->ip(),
                        'detail'     => ['on_create' => true],
                    ]);
            }
            return $t;
        });
        AuditLog::record($tenant->domain, 'superadmin', null, $this->sa($request)->username,
            'tenant.created', ['tenant' => $tenant->name, 'verified' => true,
                'admin' => isset($adminData['username']) ? mb_strtolower($adminData['username']) : null],
            $request->ip());
        return response()->json(['tenant' => $tenant->fresh(), 'verified' => true], 201);
    }

    public function tenantsShow(Request $request, Tenant $tenant)
    {
        $admins = TenantAdmin::where('tenant_id', $tenant->id)->orderBy('username')->get();
        $agents = Agent::where('domain', $tenant->domain)->where('user', $tenant->dynalink_user)->count();
        return response()->json(['tenant' => $tenant, 'admins' => $admins, 'agents_count' => $agents]);
    }

    public function tenantsUpdate(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'dynalink_pass' => 'sometimes|required|string',
            'company_name' => 'sometimes|nullable|string|max:190',
            'main_number' => 'sometimes|nullable|string|max:32',
            'status' => 'sometimes|in:active,deactivated',
        ]);
        // Locked after creation: Dynalink identity + main number.
        foreach (['domain', 'dynalink_user'] as $k) {
            if ($request->has($k) && (string) $request->input($k) !== (string) $tenant->{$k}) {
                return response()->json(['message' => 'The Dynalink identity cannot be changed after creation.'], 422);
            }
        }
        if (array_key_exists('main_number', $data) && $data['main_number'] !== null && $data['main_number'] !== '') {
            $want = preg_replace('/\D/', '', (string) $data['main_number']);
            if ($tenant->main_number) {
                if ($want !== (string) $tenant->main_number) {
                    return response()->json(['message' => 'The main SMS number cannot be changed after creation.'], 422);
                }
                unset($data['main_number']);
            } else {
                // One-time legacy set: must exist in the domain's live inventory.
                try {
                    $numbers = $this->fetchAssignedNumbers(
                        $tenant->dynalink_user, $tenant->domain, $tenant->dynalink_pass);
                } catch (\RuntimeException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
                if (!in_array($want, array_column($numbers, 'digits'), true)) {
                    return response()->json(["message" => "Main number must be one of the SMS numbers on this domain."], 422);
                }
                $data['main_number'] = $want;
            }
        } else {
            unset($data['main_number']);
        }
        $verified = null;
        if (array_key_exists('dynalink_pass', $data)) {
            $tenant->forgetToken();
            $verified = $this->verifyCredential($tenant->dynalink_user, $tenant->domain, $data['dynalink_pass']);
        }
        if (($data['status'] ?? null) === 'deactivated') $tenant->forgetToken();
        $tenant->update($data);
        if (array_key_exists('main_number', $data)) {
            AuditLog::record($tenant->domain, 'superadmin', null, $this->sa($request)->username,
                'company.main-changed', ['from' => null, 'to' => $data['main_number']], $request->ip());
        }
        AuditLog::record($tenant->domain, 'superadmin', null, $this->sa($request)->username,
            'tenant.updated', ['tenant' => $tenant->name, 'keys' => array_keys($data), 'verified' => $verified],
            $request->ip());
        return response()->json(['tenant' => $tenant->fresh(), 'verified' => $verified]);
    }

    public function tenantsDeactivate(Request $request, Tenant $tenant)
    {
        $tenant->update(['status' => 'deactivated']);
        $tenant->forgetToken();
        AuditLog::record($tenant->domain, 'superadmin', null, $this->sa($request)->username,
            'tenant.deactivated', ['tenant' => $tenant->name], $request->ip());
        return response()->json(['tenant' => $tenant->fresh()]);
    }

    public function tenantsReactivate(Request $request, Tenant $tenant)
    {
        $tenant->update(['status' => 'active']);
        AuditLog::record($tenant->domain, 'superadmin', null, $this->sa($request)->username,
            'tenant.reactivated', ['tenant' => $tenant->name], $request->ip());
        return response()->json(['tenant' => $tenant->fresh()]);
    }

    // ---------------- Tenant admins ----------------

    public function adminsIndex(Request $request, Tenant $tenant)
    {
        return response()->json(
            TenantAdmin::where('tenant_id', $tenant->id)->orderBy('username')->get());
    }

    public function adminsStore(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+$/i'],
            'first_name' => 'required|string|max:60',
            'last_name' => 'required|string|max:60',
            'password' => 'required|string|min:8|max:200',
            'secret_question' => 'required|string|max:200',
            'secret_answer' => 'required|string|max:200',
        ]);
        $username = mb_strtolower($data['username']);
        if (TenantAdmin::where('tenant_id', $tenant->id)->whereRaw('LOWER(username) = ?', [$username])->exists()) {
            return response()->json(['message' => 'That username is taken on this tenant.'], 422);
        }
        $admin = TenantAdmin::create([
            'tenant_id' => $tenant->id, 'username' => $username,
            'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
            'password_hash' => Hash::make($data['password']),
            'secret_question' => trim($data['secret_question']),
            'secret_answer_hash' => Hash::make(AgentController::normalizeAnswer($data['secret_answer'])),
            'status' => 'active',
        ]);
        PasswordPolicyService::startCycle($admin, PasswordHistory::TYPE_ADMIN,
            PasswordPolicyService::T_ADMIN, [
                'domain'     => $tenant->domain,
                'actor_type' => 'superadmin',
                'actor_id'   => null,
                'actor_name' => $this->sa($request)->username,
                'ip'         => $request->ip(),
                'detail'     => ['on_create' => true],
            ]);
        AuditLog::record($tenant->domain, 'superadmin', null, $this->sa($request)->username,
            'tenant.admin.created', ['tenant' => $tenant->name, 'admin' => $username], $request->ip());
        \App\Models\Agent::bustList($tenant->domain, $tenant->dynalink_user);
        return response()->json($admin->fresh(), 201);
    }

    public function adminsUpdate(Request $request, Tenant $tenant, TenantAdmin $admin)
    {
        abort_unless($admin->tenant_id === $tenant->id, 404);
        $data = $request->validate([
            'first_name' => 'sometimes|required|string|max:60',
            'last_name' => 'sometimes|required|string|max:60',
            'secret_question' => 'sometimes|required|string|max:200',
            'secret_answer' => 'sometimes|required|string|max:200',
            'status' => 'sometimes|in:active,deactivated',
        ]);
        if (isset($data['secret_answer'])) {
            $data['secret_answer_hash'] = Hash::make(AgentController::normalizeAnswer($data['secret_answer']));
            unset($data['secret_answer']);
        }
        $admin->update($data);
        AuditLog::record($tenant->domain, 'superadmin', null, $this->sa($request)->username,
            'tenant.admin.updated', ['tenant' => $tenant->name, 'admin' => $admin->username,
                'keys' => array_keys($data)], $request->ip());
        \App\Models\Agent::bustList($tenant->domain, $tenant->dynalink_user);
        return response()->json($admin->fresh());
    }

    /** Force-set a tenant admin's password, gated by the SUPERADMIN's own password. */
    public function adminsPassword(Request $request, Tenant $tenant, TenantAdmin $admin)
    {
        abort_unless($admin->tenant_id === $tenant->id, 404);
        $data = $request->validate([
            'superadmin_password' => 'required|string',
            'new_password' => 'required|string|min:8|max:200',
        ]);
        $sa = $this->sa($request);
        if (!Hash::check($data['superadmin_password'], $sa->password_hash)) {
            return response()->json(['message' => 'Incorrect superadmin password.'], 403);
        }
        $admin->update([
            'password_hash' => Hash::make($data['new_password']),
            'session_version' => $admin->session_version + 1, // their sessions drop
        ]);
        AuditLog::record($tenant->domain, 'superadmin', null, $sa->username,
            'tenant.admin.password-forced', ['tenant' => $tenant->name, 'admin' => $admin->username],
            $request->ip());
        return response()->json(['ok' => true]);
    }

    // ---------------- Global settings ----------------

    public function settingsShow(Request $request)
    {
        $idInDb = AppSetting::where('key', 'dynalink.client_id')->exists();
        $secretInDb = AppSetting::where('key', 'dynalink.client_secret')->exists();
        $id = (string) (Settings::get('dynalink.client_id', config('services.dynalink.client_id', '')) ?? '');
        $secret = (string) (Settings::get('dynalink.client_secret', config('services.dynalink.client_secret', '')) ?? '');
        $whInDb = AppSetting::where('key', 'dynalink.webhook_url')->exists();
        $whEnv = (string) (config('services.dynalink.webhook_url', '') ?? '');
        $whAuto = rtrim(config('app.url'), '/') . '/api/webhooks/dynalink';
        $whDb = $whInDb ? (string) (Settings::get('dynalink.webhook_url', '') ?? '') : '';
        $whEffective = $whDb !== '' ? $whDb : ($whEnv !== '' ? $whEnv : $whAuto);
        return response()->json([
            'dynalink_client_id' => [
                'value' => $id,
                'source' => $idInDb ? 'database' : ($id !== '' ? 'env' : 'none'),
            ],
            'dynalink_client_secret' => [
                'set' => $secret !== '',
                'source' => $secretInDb ? 'database' : ($secret !== '' ? 'env' : 'none'),
            ],
            'webhook_url' => [
                'value' => $whEffective,
                'override' => $whDb,
                'source' => $whInDb ? 'database' : ($whEnv !== '' ? 'env' : 'auto'),
                'auto_value' => $whAuto,
            ],
            'api_servers' => [
                'value' => (string) (Settings::get(\App\Services\ApiServerPool::SETTING, '') ?? ''),
                'pool' => \App\Services\ApiServerPool::status(),
                'default_host' => config('services.dynalink.auth_base'),
                'pool_auth' => filter_var(config('services.dynalink.pool_auth', false), FILTER_VALIDATE_BOOL),
                'rate_per_sec' => \App\Services\ApiServerPool::ratePerSec(),
            ],
            'legacy_login' => $this->legacyState(),
            'require_correlation_id' => [
                'enabled' => Settings::get('webhook.require_correlation_id', '0') === '1',
            ],
            'mail' => $this->mailState(),
            'branding' => \App\Services\Branding::state(),
        ]);
    }

    /** Email gateway state: per-field value/source, passwords as set-flags. */
    protected function mailState(): array
    {
        $env = config('services.mail', []);
        $field = function (string $key, string $envKey) use ($env) {
            $inDb = AppSetting::where('key', $key)->exists();
            $v = (string) (Settings::get($key, $env[$envKey] ?? '') ?? '');
            return ['value' => $v, 'source' => $inDb ? 'database' : ($v !== '' ? 'env' : 'none')];
        };
        $secret = function (string $key, string $envKey) use ($env) {
            $inDb = AppSetting::where('key', $key)->exists();
            $set = (string) (Settings::get($key, $env[$envKey] ?? '') ?? '') !== '';
            return ['set' => $set, 'source' => $inDb ? 'database' : ($set ? 'env' : 'none')];
        };
        return [
            'smtp_host' => $field('mail.smtp.host', 'smtp_host'),
            'smtp_port' => $field('mail.smtp.port', 'smtp_port'),
            'smtp_encryption' => $field('mail.smtp.encryption', 'smtp_encryption'),
            'smtp_username' => $field('mail.smtp.username', 'smtp_username'),
            'smtp_password' => $secret('mail.smtp.password', 'smtp_password'),
            'from_address' => $field('mail.from.address', 'from_address'),
            'from_name' => $field('mail.from.name', 'from_name'),
            'imap_host' => $field('mail.imap.host', 'imap_host'),
            'imap_port' => $field('mail.imap.port', 'imap_port'),
            'imap_encryption' => $field('mail.imap.encryption', 'imap_encryption'),
            'imap_username' => $field('mail.imap.username', 'imap_username'),
            'imap_password' => $secret('mail.imap.password', 'imap_password'),
            'inbound_domain' => $field('mail.inbound_domain', 'inbound_domain'),
            'cap_sender_daily' => $field('mail.cap_sender_daily', 'cap_sender_daily'),
            'cap_dest_hourly' => $field('mail.cap_dest_hourly', 'cap_dest_hourly'),
            'last_poll' => \Illuminate\Support\Facades\Cache::get('emailsms:last_poll'),
        ];
    }

    protected function legacyState(): array
    {
        $enabled = Settings::legacyLoginEnabled();
        $until = Settings::get('auth.legacy_dynalink_until', null);
        $until = ($until === null || $until === '') ? null : (int) $until;
        return [
            'enabled' => $enabled,
            'until' => $until,
            'until_human' => $until ? date('Y-m-d H:i:s', $until) : null,
        ];
    }

    public function settingsUpdate(Request $request)
    {
        $data = $request->validate([
            'dynalink_client_id' => 'sometimes|nullable|string|max:120',
            'dynalink_client_secret' => 'sometimes|nullable|string|max:200',
            'legacy_login_enabled' => 'sometimes|boolean',
            'legacy_login_minutes' => 'sometimes|nullable|integer|min:15|max:10080',
            'webhook_url' => 'sometimes|nullable|string|max:500',
            'require_correlation_id' => 'sometimes|boolean',
            'api_servers' => 'sometimes|nullable|string|max:4000',
            'api_rate_per_sec' => 'sometimes|nullable|integer|min:0|max:1000',
            'clear_api_penalties' => 'sometimes|boolean',
            'mail_smtp_host' => 'sometimes|nullable|string|max:190',
            'mail_smtp_port' => 'sometimes|nullable|integer|min:1|max:65535',
            'mail_smtp_encryption' => 'sometimes|nullable|in:ssl,tls,none',
            'mail_smtp_username' => 'sometimes|nullable|string|max:190',
            'mail_smtp_password' => 'sometimes|nullable|string|max:500',
            'mail_from_address' => 'sometimes|nullable|email|max:190',
            'mail_from_name' => 'sometimes|nullable|string|max:120',
            'mail_imap_host' => 'sometimes|nullable|string|max:190',
            'mail_imap_port' => 'sometimes|nullable|integer|min:1|max:65535',
            'mail_imap_encryption' => 'sometimes|nullable|in:ssl,tls,none',
            'mail_imap_username' => 'sometimes|nullable|string|max:190',
            'mail_imap_password' => 'sometimes|nullable|string|max:500',
            'mail_inbound_domain' => 'sometimes|nullable|string|max:190',
            'mail_cap_sender_daily' => 'sometimes|nullable|integer|min:0|max:1000000',
            'mail_cap_dest_hourly' => 'sometimes|nullable|integer|min:0|max:1000000',
        ]);
        if (array_key_exists('api_servers', $data)) {
            $raw = (string) ($data['api_servers'] ?? '');
            $clean = [];
            foreach (preg_split('/[\r\n,]+/', $raw) as $line) {
                if (trim($line) === '') continue;
                $u = \App\Services\ApiServerPool::normalize($line);
                if ($u === '') {
                    return response()->json([
                        'message' => "\"" . trim($line) . "\" is not a valid server — use http:// or https://.",
                    ], 422);
                }
                if (!in_array($u, $clean, true)) $clean[] = $u;
            }
            Settings::set(\App\Services\ApiServerPool::SETTING, implode("\n", $clean));
            \App\Services\ApiServerPool::clearPenalties();   // give every host a fresh start
            AuditLog::record(null, 'superadmin', null, $this->sa($request)->username,
                'settings.api-servers-changed', ['count' => count($clean), 'servers' => $clean],
                $request->ip());
        }
        if (array_key_exists('api_rate_per_sec', $data)) {
            $r = max(0, (int) ($data['api_rate_per_sec'] ?? 0));
            Settings::set(\App\Services\ApiServerPool::RATE_SETTING, (string) $r);
            AuditLog::record(null, 'superadmin', null, $this->sa($request)->username,
                'settings.api-rate-changed', ['per_sec' => $r], $request->ip());
        }
        if (!empty($data['clear_api_penalties'])) {
            \App\Services\ApiServerPool::clearPenalties();
        }
        if (array_key_exists('webhook_url', $data)) {
            $w = rtrim(trim((string) ($data['webhook_url'] ?? '')), '/');
            if ($w !== '' && !preg_match('/^https?:\/\/.+/i', $w)) {
                return response()->json(['message' => 'Webhook URL must start with http:// or https://.'], 422);
            }
            $data['webhook_url'] = $w;
        }
        if (array_key_exists('mail_inbound_domain', $data)) {
            $d = strtolower(trim((string) ($data['mail_inbound_domain'] ?? '')));
            if ($d !== '' && (str_contains($d, '@') || str_contains($d, ' ') || str_contains($d, '://') || !str_contains($d, '.'))) {
                return response()->json(['message' => 'Inbound domain must be a bare domain like sms.example.com.'], 422);
            }
            $data['mail_inbound_domain'] = $d;
        }
        $whKeySaved = array_key_exists('webhook_url', $data);
        $oldWh = $whKeySaved ? $this->webhookEffective() : null;
        $map = ['dynalink_client_id' => 'dynalink.client_id',
            'dynalink_client_secret' => 'dynalink.client_secret',
            'webhook_url' => 'dynalink.webhook_url'];
        foreach ($map as $in => $key) {
            if (!array_key_exists($in, $data)) continue;
            $v = trim((string) ($data[$in] ?? ''));
            if ($v === '') {
                // Empty clears the DB override (falls back to .env).
                AppSetting::where('key', $key)->delete();
                Cache::forget('app_settings:all');
            } else {
                Settings::set($key, $v);
            }
        }
        // Email gateway: same DB-override pattern (empty clears to .env).
        // Contract: the UI sends password keys only when typed (blank keeps);
        // the revert buttons send '' explicitly to clear the override.
        $mailMap = ['mail_smtp_host' => 'mail.smtp.host',
            'mail_smtp_port' => 'mail.smtp.port',
            'mail_smtp_encryption' => 'mail.smtp.encryption',
            'mail_smtp_username' => 'mail.smtp.username',
            'mail_from_address' => 'mail.from.address',
            'mail_from_name' => 'mail.from.name',
            'mail_imap_host' => 'mail.imap.host',
            'mail_imap_port' => 'mail.imap.port',
            'mail_imap_encryption' => 'mail.imap.encryption',
            'mail_imap_username' => 'mail.imap.username',
            'mail_inbound_domain' => 'mail.inbound_domain',
            'mail_cap_sender_daily' => 'mail.cap_sender_daily',
            'mail_cap_dest_hourly' => 'mail.cap_dest_hourly'];
        foreach ($mailMap as $in => $key) {
            if (!array_key_exists($in, $data)) continue;
            $v = trim((string) ($data[$in] ?? ''));
            if ($v === '') {
                AppSetting::where('key', $key)->delete();
                Cache::forget('app_settings:all');
            } else {
                Settings::set($key, $v);
            }
        }
        foreach (['mail_smtp_password' => 'mail.smtp.password',
            'mail_imap_password' => 'mail.imap.password'] as $in => $key) {
            if (!array_key_exists($in, $data)) continue;
            $v = (string) ($data[$in] ?? '');
            if ($v === '') {
                AppSetting::where('key', $key)->delete();
                Cache::forget('app_settings:all');
            } else {
                Settings::set($key, $v);
            }
        }
        if (array_key_exists('require_correlation_id', $data)) {
            if ($data['require_correlation_id']) {
                Settings::set('webhook.require_correlation_id', '1');
            } else {
                AppSetting::where('key', 'webhook.require_correlation_id')->delete();
                Cache::forget('app_settings:all');
            }
        }
        if (array_key_exists('legacy_login_enabled', $data)) {
            if ($data['legacy_login_enabled']) {
                Settings::set('auth.legacy_dynalink_login', '1');
                $mins = $data['legacy_login_minutes'] ?? null;
                if ($mins) {
                    Settings::set('auth.legacy_dynalink_until', (string) (time() + ((int) $mins) * 60));
                } else {
                    AppSetting::where('key', 'auth.legacy_dynalink_until')->delete();
                    Cache::forget('app_settings:all');
                }
            } else {
                AppSetting::where('key', 'auth.legacy_dynalink_login')->delete();
                AppSetting::where('key', 'auth.legacy_dynalink_until')->delete();
                Cache::forget('app_settings:all');
            }
        }
        $resub = null;
        $auditExtra = [];
        if ($whKeySaved) {
            $newWh = $this->webhookEffective();
            if ($newWh !== $oldWh) {
                $tenants = $this->resubscribeAllTenants();
                $resub = ['changed' => true, 'old_url' => $oldWh, 'new_url' => $newWh, 'tenants' => $tenants];
                $failedNames = array_values(array_map(fn($t) => $t['tenant'],
                    array_filter($tenants, fn($t) => !$t['ok'])));
                $auditExtra['resubscribed'] = ['ok' => count($tenants) - count($failedNames),
                    'failed' => count($failedNames), 'failed_tenants' => $failedNames];
            } else {
                $resub = ['changed' => false, 'url' => $newWh];
            }
        }
        AuditLog::record(null, 'superadmin', null, $this->sa($request)->username,
            'setting.updated', array_merge(['keys' => array_keys($data)], $auditExtra), $request->ip());
        $resp = $this->settingsShow($request)->getData(true);
        if ($resub !== null) $resp['resubscribed'] = $resub;
        return response()->json($resp);
    }

    /** POST /superadmin/settings/branding — app name + logo (multipart). */
    public function brandingUpdate(Request $request)
    {
        $data = $request->validate([
            'app_name' => 'sometimes|nullable|string|max:60',
            'logo' => 'sometimes|nullable|image|mimes:jpeg,png,webp,gif,svg|max:512',
            'logo_clear' => 'sometimes|boolean',
        ]);
        if (array_key_exists('app_name', $data)) \App\Services\Branding::setName($data['app_name']);
        if ($request->hasFile('logo')) \App\Services\Branding::saveLogo($request->file('logo'));
        elseif (!empty($data['logo_clear'])) \App\Services\Branding::clearLogo();
        AuditLog::record(null, 'superadmin', null, $this->sa($request)->username,
            'branding.updated', ['keys' => array_keys($data)], $request->ip());
        return response()->json($this->settingsShow($request)->getData(true));
    }

    /** POST /superadmin/settings/mail-test — test email send or inbox check. */
    public function mailTest(Request $request)
    {
        $data = $request->validate([
            'to' => 'required_without:check_inbox|nullable|email|max:190',
            'check_inbox' => 'sometimes|boolean',
        ]);
        try {
            if (!empty($data['check_inbox'])) {
                $n = app(\App\Services\EmailSmsService::class)->checkInbox();
                return response()->json(['ok' => true, 'folders' => $n]);
            }
            app(\App\Services\EmailSmsService::class)->testSend($data['to']);
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Effective webhook/post-url with the current DB override applied. */
    protected function webhookEffective(): string
    {
        $db = (string) (Settings::get('dynalink.webhook_url', '') ?? '');
        if ($db !== '') return $db;
        $env = (string) (config('services.dynalink.webhook_url', '') ?? '');
        if ($env !== '') return $env;
        return rtrim(config('app.url'), '/') . '/api/webhooks/dynalink';
    }

    /**
     * A post-url change orphans every tenant's Dynalink entries (they would
     * keep POSTing to the old URL), so recreate each active tenant's
     * subscriptions NOW instead of waiting for login/refresh. Never
     * throws — per-tenant failures come back in the summary.
     */
    protected function resubscribeAllTenants(): array
    {
        $out = [];
        $tenants = Tenant::where('status', 'active')->orWhereNull('status')->get();
        foreach ($tenants as $tenant) {
            try {
                $token = $tenant->accessToken();
            } catch (\Throwable $e) {
                Log::warning('Post-url resubscribe: tenant token failed',
                    ['tenant' => $tenant->name, 'error' => $e->getMessage()]);
                $out[] = ['tenant' => $tenant->name, 'ok' => false,
                    'detail' => 'token failed: ' . substr($e->getMessage(), 0, 200), 'results' => []];
                continue;
            }
            try {
                $results = app(SubscriptionController::class)
                    ->recreateForScope($tenant->domain, $tenant->dynalink_user, $token);
            } catch (\Throwable $e) {
                Log::warning('Post-url resubscribe failed',
                    ['tenant' => $tenant->name, 'error' => $e->getMessage()]);
                $out[] = ['tenant' => $tenant->name, 'ok' => false,
                    'detail' => 'error: ' . substr($e->getMessage(), 0, 200), 'results' => []];
                continue;
            }
            $parts = [];
            $ok = true;
            foreach ($results as $model => $r) {
                $a = $r['action'] ?? 'failed';
                if ($a === 'failed') $ok = false;
                $parts[] = $model . '=' . $a . (isset($r['id']) ? ' #' . $r['id'] : '');
            }
            $out[] = ['tenant' => $tenant->name, 'ok' => $ok,
                'detail' => implode(', ', $parts), 'results' => $results];
        }
        return $out;
    }

    // ---------------- Audit (global, unscoped) ----------------

    public function auditIndex(Request $request)
    {
        $q = AuditLog::orderByDesc('id');
        if ($request->filled('domain')) $q->where('domain', $request->query('domain'));
        if ($request->filled('action')) $q->where('action', $request->query('action'));
        if ($request->filled('actor')) {
            $like = '%' . $request->query('actor') . '%';
            $q->where(fn($w) => $w->where('actor_name', 'like', $like)->orWhere('actor_type', 'like', $like));
        }
        if ($request->filled('from')) $q->where('created_at', '>=', $request->query('from'));
        if ($request->filled('to')) {
            $to = $request->query('to');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to .= ' 23:59:59';
            $q->where('created_at', '<=', $to);
        }
        $per = min(max((int) $request->query('per_page', 50), 1), 500);
        return response()->json($q->paginate($per));
    }

    public function auditActions(Request $request)
    {
        return response()->json(
            AuditLog::distinct()->orderBy('action')->pluck('action'));
    }

    // ---------------- Helpers ----------------

    // ---------------- Allowed IPs ----------------

    public function ipsIndex(Request $request)
    {
        return response()->json([
            'your_ip' => (string) $request->ip(),
            'ips' => SuperAdminAllowedIp::orderBy('cidr')->get(),
        ]);
    }

    public function ipsStore(Request $request)
    {
        $data = $request->validate([
            'cidr' => 'required|string|max:60',
            'label' => 'sometimes|nullable|string|max:120',
        ]);
        $cidr = trim($data['cidr']);
        if (!EnsureSuperAdminIp::valid($cidr)) {
            return response()->json(['message' => 'Use an IP (v4/v6) or an IPv4 CIDR like 192.168.1.0/24.'], 422);
        }
        $ip = SuperAdminAllowedIp::firstOrCreate(['cidr' => $cidr],
            ['label' => $data['label'] ?? null]);
        Cache::forget('superadmin:ips');
        AuditLog::record(null, 'superadmin', null, $this->sa($request)->username,
            'superadmin.ip.added', ['cidr' => $cidr], $request->ip());
        return response()->json($ip, 201);
    }

    public function ipsDestroy(Request $request, SuperAdminAllowedIp $ip)
    {
        $cidr = $ip->cidr;
        $ip->delete();
        Cache::forget('superadmin:ips');
        AuditLog::record(null, 'superadmin', null, $this->sa($request)->username,
            'superadmin.ip.removed', ['cidr' => $cidr], $request->ip());
        return response()->json(['ok' => true]);
    }

    public function webhookIpsIndex(Request $request)
    {
        return response()->json([
            'ips' => WebhookAllowedIp::orderBy('cidr')->get(),
        ]);
    }

    public function webhookIpsStore(Request $request)
    {
        $data = $request->validate([
            'cidr' => 'required|string|max:60',
            'label' => 'sometimes|nullable|string|max:120',
        ]);
        $cidr = trim($data['cidr']);
        if (!EnsureSuperAdminIp::valid($cidr)) {
            return response()->json(['message' => 'Use an IP (v4/v6) or an IPv4 CIDR like 192.168.1.0/24.'], 422);
        }
        $ip = WebhookAllowedIp::firstOrCreate(['cidr' => $cidr],
            ['label' => $data['label'] ?? null]);
        Cache::forget('webhook:ips');
        AuditLog::record(null, 'superadmin', null, $this->sa($request)->username,
            'webhook.ip.added', ['cidr' => $cidr], $request->ip());
        return response()->json($ip, 201);
    }

    public function webhookIpsDestroy(Request $request, WebhookAllowedIp $ip)
    {
        $cidr = $ip->cidr;
        $ip->delete();
        Cache::forget('webhook:ips');
        AuditLog::record(null, 'superadmin', null, $this->sa($request)->username,
            'webhook.ip.removed', ['cidr' => $cidr], $request->ip());
        return response()->json(['ok' => true]);
    }

    // ---------------- Deletes (2-step: typed name + superadmin password) ----------------

    /** What a tenant delete would destroy. Shared-domain rows are kept, never shown. */
    public function tenantsDeletePreview(Request $request, Tenant $tenant)
    {
        return response()->json([
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'status' => $tenant->status],
            'counts' => $this->tenantPurge($tenant, false),
        ]);
    }

    public function tenantsDestroy(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'confirm_name' => 'required|string',
            'superadmin_password' => 'required|string',
        ]);
        $sa = $this->sa($request);
        if (mb_strtolower(trim($data['confirm_name'])) !== mb_strtolower($tenant->name)) {
            return response()->json(['message' => 'Typed name does not match this tenant.'], 422);
        }
        if (!Hash::check($data['superadmin_password'], $sa->password_hash)) {
            return response()->json(['message' => 'Incorrect superadmin password.'], 403);
        }
        // Best-effort: remove our Dynalink subscriptions while we still can.
        try {
            $token = $tenant->accessToken();
            foreach (['message', 'messagesession'] as $model) {
                $key = "dynalink:sub:{$tenant->domain}:{$tenant->dynalink_user}:{$model}";
                if (($subId = Cache::get($key)) && $subId !== 'external') {
                    app(DynalinkService::class)->deleteSubscription($token, $subId);
                }
            }
        } catch (\Throwable $e) {}
        $name = $tenant->name; $domain = $tenant->domain; $dlUser = $tenant->dynalink_user;
        $tenantId = $tenant->id;
        $counts = DB::transaction(fn() => $this->tenantPurge($tenant, true));
        Cache::forget("dynalink:tenant_token:{$tenantId}");
        Cache::forget("dynalink:rt:{$domain}:{$dlUser}");
        foreach (['message', 'messagesession'] as $model) {
            Cache::forget("dynalink:sub:{$domain}:{$dlUser}:{$model}");
        }
        Cache::forget("dynalink:sub:{$domain}:{$dlUser}:last_error");
        AuditLog::record($domain, 'superadmin', null, $sa->username,
            'tenant.deleted', ['tenant' => $name, 'counts' => $counts], $request->ip());
        return response()->json(['ok' => true, 'counts' => $counts]);
    }

    /**
     * Tenant destruction inventory. When $delete is true, destroys everything
     * inventoried (inside the caller's transaction) and returns the counts.
     * Audit logs are deliberately kept — they are the trail. Domain-only rows
     * are kept when another tenant shares the domain.
     */
    protected function tenantPurge(Tenant $tenant, bool $delete = false): array
    {
        $d = $tenant->domain; $u = $tenant->dynalink_user;
        $shared = Tenant::where('domain', $d)->where('id', '!=', $tenant->id)->exists();
        $agentIds = Agent::where('domain', $d)->where('user', $u)->pluck('id')->all();
        $counts = [
            'admins' => TenantAdmin::where('tenant_id', $tenant->id)->count(),
            'agents' => count($agentIds),
        ];
        $tables = [
            'templates' => Template::where('domain', $d)->where('user', $u),
            'scheduled' => ScheduledMessage::where('domain', $d)->where('user', $u),
            'auto_replies' => AutoReply::where('domain', $d)->where('user', $u),
            'auto_reply_logs' => AutoReplyLog::where('domain', $d)->where('user', $u),
            // Tenant-wide now — not viewer-scoped.
            'conversation_meta' => ConversationMeta::where('domain', $d),
            'webhook_events' => WebhookEvent::where('domain', $d)->where('user', $u),
            'integrations' => Integration::where('domain', $d)->where('user', $u),
            'integration_assignments' => IntegrationNumber::where('domain', $d)->where('user', $u),
            'integration_sessions' => IntegrationSession::whereIn('integration_id', Integration::where('domain', $d)->where('user', $u)->pluck('id')),
            'opt_events' => $shared ? null : OptEvent::where('domain', $d),
        ];
        foreach ($tables as $k => $q) {
            $counts[$k] = $q ? (clone $q)->count() : 0;
            if ($delete && $q) $q->delete();
        }
        $rq = PasswordResetRequest::query();
        if (empty($agentIds) && $shared) {
            $rq->whereRaw('1 = 0');
        } else {
            $rq->where(function ($w) use ($agentIds, $d, $shared) {
                if (!empty($agentIds)) $w->orWhereIn('agent_id', $agentIds);
                if (!$shared) $w->orWhere('domain', $d);
            });
        }
        $counts['reset_requests'] = (clone $rq)->count();
        if ($delete) $rq->delete();
        $like = fn($s) => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
        $lq = LoginAttempt::where('username', 'like', $like('@' . $tenant->name));
        if (!$shared) $lq->orWhere('username', 'like', $like('@' . $d));
        $counts['login_attempts'] = (clone $lq)->count();
        if ($delete) $lq->delete();
        if ($delete) {
            Agent::where('domain', $d)->where('user', $u)->delete();
            TenantAdmin::where('tenant_id', $tenant->id)->delete();
            $tenant->delete();
        }
        return $counts;
    }

    public function adminsDeletePreview(Request $request, Tenant $tenant, TenantAdmin $admin)
    {
        abort_unless($admin->tenant_id === $tenant->id, 404);
        $total = TenantAdmin::where('tenant_id', $tenant->id)->count();
        return response()->json([
            'admin' => ['id' => $admin->id, 'username' => $admin->username],
            'is_last_admin' => $total <= 1,
            'reset_requests' => TenantPasswordResetRequest::where('tenant_admin_id', $admin->id)->count(),
        ]);
    }

    public function adminsDestroy(Request $request, Tenant $tenant, TenantAdmin $admin)
    {
        abort_unless($admin->tenant_id === $tenant->id, 404);
        $data = $request->validate([
            'confirm_username' => 'required|string',
            'superadmin_password' => 'required|string',
        ]);
        if (mb_strtolower(trim($data['confirm_username'])) !== mb_strtolower($admin->username)) {
            return response()->json(['message' => 'Typed username does not match this admin.'], 422);
        }
        $sa = $this->sa($request);
        if (!Hash::check($data['superadmin_password'], $sa->password_hash)) {
            return response()->json(['message' => 'Incorrect superadmin password.'], 403);
        }
        $username = $admin->username;
        $admin->delete(); // their reset requests cascade
        \App\Models\Agent::bustList($tenant->domain, $tenant->dynalink_user);
        AuditLog::record($tenant->domain, 'superadmin', null, $sa->username,
            'tenant.admin.deleted', ['tenant' => $tenant->name, 'admin' => $username], $request->ip());
        return response()->json(['ok' => true]);
    }

    /** Live-check a Dynalink credential. Never throws; false just means "unverified". */
    protected function verifyCredential(string $user, string $domain, string $pass): bool
    {
        try {
            app(DynalinkService::class)->login($user . '@' . $domain, $pass);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

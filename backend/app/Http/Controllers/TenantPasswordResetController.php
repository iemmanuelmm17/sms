<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PasswordHistory;
use App\Rules\PasswordPolicy;
use App\Services\PasswordPolicyService;
use App\Models\Tenant;
use App\Models\TenantAdmin;
use App\Models\TenantPasswordResetRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Tenant-admin forgot-password: username@tenantname -> secret answer -> new password.
 * Mirrors the agent flow: uniform-200 decoys (no enumeration) + per-account
 * caps across challenge restarts + per-IP route throttling.
 */
class TenantPasswordResetController extends Controller
{
    public const TTL_SECS = 900; // challenge lives 15 minutes
    public const MAX_ATTEMPTS = 5;
    public const MAX_STARTS_PER_ACCT = 5;   // fresh challenges per account / 15 min
    public const MAX_ANSWERS_PER_ACCT = 10; // wrong answers per account / 30 min
    public const ANSWER_WINDOW_SECS = 1800;
    public const FAKE_PREFIX = 'X'; // decoy challenges (real ones start with 'R')

    public const GENERIC_FAIL = "We couldn't verify that username. Check the username@tenantname format and try again.";

    public static function parseLogin(string $input): ?array
    {
        $parts = explode('@', trim($input), 2);
        if (count($parts) !== 2) return null;
        [$name, $tenant] = [mb_strtolower(trim($parts[0])), mb_strtolower(trim($parts[1]))];
        if (!preg_match('/^[a-z0-9]+$/', $name) || !preg_match('/^[a-z0-9-]+$/', $tenant)) return null;
        return [$name, $tenant];
    }

    /** POST /api/tenant/forgot/start { username } -> { challenge, question } */
    public function start(Request $request)
    {
        $input = (string) $request->input('username', '');
        $parsed = self::parseLogin($input);
        // Per-account start cap (survives challenge restarts) + stale cleanup.
        $acctKey = 'forgot:start:tenant:' . ($parsed ? $parsed[1] . ':' . $parsed[0] : 'invalid');
        if (RateLimiter::tooManyAttempts($acctKey, self::MAX_STARTS_PER_ACCT)) {
            return response()->json(['message' => 'Too many reset attempts. Try again later.'], 429);
        }
        RateLimiter::hit($acctKey, self::TTL_SECS);
        TenantPasswordResetRequest::where('created_at', '<', now()->subDay())->delete();
        $tenant = $parsed ? Tenant::where('name', $parsed[1])->first() : null;
        $admin = ($tenant && $tenant->isActive())
            ? TenantAdmin::where('tenant_id', $tenant->id)->where('username', $parsed[0])->first()
            : null;
        if (!$admin || !$admin->isActive() || !$admin->secret_question || !$admin->secret_answer_hash) {
            AuditLog::record($tenant?->domain, 'unknown', null, $input,
                'tenant.forgot.denied', [], $request->ip());
            // Uniform 200: decoy challenge + generic question (no enumeration).
            return response()->json([
                'challenge' => self::FAKE_PREFIX . Str::random(39),
                'question' => 'What is the answer to your secret question?',
            ]);
        }
        $plain = 'R' . Str::random(39); // 'R' namespace: real challenges only
        TenantPasswordResetRequest::create([
            'tenant_admin_id' => $admin->id, 'tenant_id' => $tenant->id,
            'domain' => $tenant->domain, 'username' => $admin->username,
            'token_hash' => hash('sha256', $plain), 'ip_address' => $request->ip(),
        ]);
        AuditLog::record($tenant->domain, 'unknown', null, $admin->username,
            'tenant.forgot.started', [], $request->ip());
        return response()->json(['challenge' => $plain, 'question' => $admin->secret_question]);
    }

    /** POST /api/tenant/forgot/answer { challenge, answer } */
    public function answer(Request $request)
    {
        $data = $request->validate(['challenge' => 'required|string', 'answer' => 'required|string']);
        if (str_starts_with($data['challenge'], self::FAKE_PREFIX)) {
            return response()->json(['message' => 'Incorrect answer.'], 422);
        }
        $req = $this->liveChallenge($data['challenge']);
        if (!$req) return response()->json(['message' => 'That reset link expired. Start over.'], 422);
        $ansKey = 'forgot:answer:tenant:' . $req->domain . ':' . $req->username;
        if (RateLimiter::tooManyAttempts($ansKey, self::MAX_ANSWERS_PER_ACCT)) {
            return response()->json(['message' => 'Too many attempts. Try again later.'], 429);
        }
        $req->increment('attempts');
        if ($req->attempts > self::MAX_ATTEMPTS) {
            $req->update(['completed_at' => now()]);
            return response()->json(['message' => 'Too many wrong answers. Start over.'], 422);
        }
        $admin = TenantAdmin::find($req->tenant_admin_id);
        $ok = $admin && $admin->isActive()
            && Hash::check(AgentController::normalizeAnswer($data['answer']), (string) $admin->secret_answer_hash);
        if (!$ok) {
            RateLimiter::hit($ansKey, self::ANSWER_WINDOW_SECS);
            AuditLog::record($req->domain, 'unknown', null, (string) $req->username,
                'tenant.forgot.bad-answer', [], $request->ip());
            return response()->json(['message' => 'Incorrect answer.'], 422);
        }
        $req->update(['verified_at' => now()]);
        RateLimiter::clear($ansKey); // legit success resets the budget
        AuditLog::record($req->domain, 'unknown', null, (string) $req->username,
            'tenant.forgot.verified', [], $request->ip());
        return response()->json(['ok' => true]);
    }

    /** POST /api/tenant/forgot/complete { challenge, new_password } */
    public function complete(Request $request)
    {
        $data = $request->validate([
            'challenge' => 'required|string',
            'new_password' => ['required', 'string', new PasswordPolicy()],
        ]);
        $req = $this->liveChallenge($data['challenge']);
        $admin = $req ? TenantAdmin::find($req->tenant_admin_id) : null;
        if (!$req || !$req->verified_at || !$admin || !$admin->isActive()) {
            return response()->json(['message' => 'That reset link expired. Start over.'], 422);
        }
        if ($err = PasswordPolicyService::reuseError($admin, PasswordHistory::TYPE_ADMIN, $data['new_password'])) {
            return response()->json(['message' => $err], 422);
        }
        $admin->loadMissing('tenant');
        PasswordPolicyService::change($admin, PasswordHistory::TYPE_ADMIN, $data['new_password'],
            PasswordPolicyService::T_FORGOT, [
                'domain'     => $admin->tenant->domain ?? $req->domain,
                'actor_type' => 'admin',
                'actor_id'   => $admin->id,
                'actor_name' => $admin->displayName(),
                'ip'         => $request->ip(),
            ]);
        $req->update(['completed_at' => now()]);
        return response()->json(['ok' => true]);
    }

    protected function liveChallenge(string $plain): ?TenantPasswordResetRequest
    {
        return TenantPasswordResetRequest::where('token_hash', hash('sha256', $plain))
            ->whereNull('completed_at')
            ->where('created_at', '>=', now()->subSeconds(self::TTL_SECS))
            ->first();
    }
}

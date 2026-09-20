<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\PasswordHistory;
use App\Rules\PasswordPolicy;
use App\Services\PasswordPolicyService;
use App\Models\PasswordResetRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Agent forgot-password: username -> secret answer -> new password.
 * Enumeration resistance: unknown usernames get a uniform 200 with a
 * decoy challenge, and brute force is capped per account ACROSS
 * challenge restarts (plus per-IP route throttling).
 */
class AgentPasswordResetController extends Controller
{
    public const TTL_SECS = 900; // challenge lives 15 minutes
    public const MAX_ATTEMPTS = 5;
    public const MAX_STARTS_PER_ACCT = 5;   // fresh challenges per account / 15 min
    public const MAX_ANSWERS_PER_ACCT = 10; // wrong answers per account / 30 min
    public const ANSWER_WINDOW_SECS = 1800;
    public const FAKE_PREFIX = 'X'; // decoy challenges (real ones start with 'R')

    public const GENERIC_FAIL = "We couldn't verify that username. Check the username@tenant format and try again.";

    public static function parseLogin(string $input): ?array
    {
        $parts = explode('@', trim($input), 2);
        if (count($parts) !== 2) return null;
        [$name, $domain] = [mb_strtolower(trim($parts[0])), trim($parts[1])];
        if (!preg_match('/^[a-z0-9]+$/', $name) || $domain === '') return null;
        return [$name, $domain];
    }

    /** POST /api/agent/forgot/start { username } -> { challenge, question } */
    public function start(Request $request)
    {
        $input = (string) $request->input('username', '');
        $parsed = self::parseLogin($input);
        // Per-account start cap (survives challenge restarts) + stale cleanup.
        $acctKey = 'forgot:start:agent:' . ($parsed ? $parsed[1] . ':' . $parsed[0] : 'invalid');
        if (RateLimiter::tooManyAttempts($acctKey, self::MAX_STARTS_PER_ACCT)) {
            return response()->json(['message' => 'Too many reset attempts. Try again later.'], 429);
        }
        RateLimiter::hit($acctKey, self::TTL_SECS);
        PasswordResetRequest::where('created_at', '<', now()->subDay())->delete();
        $agent = $parsed ? Agent::where('domain', $parsed[1])->where('username', $parsed[0])->first() : null;
        if (!$agent || $agent->status !== 'active' || !$agent->secret_question || !$agent->secret_answer_hash) {
            AuditLog::record($parsed[1] ?? null, 'unknown', null, $input,
                'agent.forgot.denied', [], $request->ip());
            // Uniform 200: decoy challenge + generic question. Answering it
            // always yields "Incorrect answer." — indistinguishable from a
            // real account with a wrong answer (no enumeration).
            return response()->json([
                'challenge' => self::FAKE_PREFIX . Str::random(39),
                'question' => 'What is the answer to your secret question?',
            ]);
        }
        $plain = 'R' . Str::random(39); // 'R' namespace: real challenges only
        PasswordResetRequest::create([
            'agent_id' => $agent->id, 'domain' => $agent->domain, 'username' => $agent->username,
            'token_hash' => hash('sha256', $plain), 'ip_address' => $request->ip(),
        ]);
        AuditLog::record($agent->domain, 'unknown', $agent->id, $agent->username,
            'agent.forgot.started', [], $request->ip());
        return response()->json(['challenge' => $plain, 'question' => $agent->secret_question]);
    }

    /** POST /api/agent/forgot/answer { challenge, answer } */
    public function answer(Request $request)
    {
        $data = $request->validate(['challenge' => 'required|string', 'answer' => 'required|string']);
        // Decoy challenges (unknown usernames) always fail identically —
        // never reveal whether the account exists.
        if (str_starts_with($data['challenge'], self::FAKE_PREFIX)) {
            return response()->json(['message' => 'Incorrect answer.'], 422);
        }
        $req = $this->liveChallenge($data['challenge']);
        if (!$req) return response()->json(['message' => 'That reset link expired. Start over.'], 422);
        // Cross-challenge per-account cap: restarting for a fresh challenge
        // does NOT reset this budget.
        $ansKey = 'forgot:answer:agent:' . $req->domain . ':' . $req->username;
        if (RateLimiter::tooManyAttempts($ansKey, self::MAX_ANSWERS_PER_ACCT)) {
            return response()->json(['message' => 'Too many attempts. Try again later.'], 429);
        }
        $req->increment('attempts');
        if ($req->attempts > self::MAX_ATTEMPTS) {
            $req->update(['completed_at' => now()]);
            return response()->json(['message' => 'Too many wrong answers. Start over.'], 422);
        }
        $agent = Agent::find($req->agent_id);
        $ok = $agent && $agent->status === 'active'
            && Hash::check(AgentController::normalizeAnswer($data['answer']), (string) $agent->secret_answer_hash);
        if (!$ok) {
            RateLimiter::hit($ansKey, self::ANSWER_WINDOW_SECS);
            AuditLog::record($req->domain, 'unknown', $req->agent_id, (string) $req->username,
                'agent.forgot.bad-answer', [], $request->ip());
            return response()->json(['message' => 'Incorrect answer.'], 422);
        }
        $req->update(['verified_at' => now()]);
        RateLimiter::clear($ansKey); // legit success resets the budget
        AuditLog::record($req->domain, 'unknown', $req->agent_id, (string) $req->username,
            'agent.forgot.verified', [], $request->ip());
        return response()->json(['ok' => true]);
    }

    /** POST /api/agent/forgot/complete { challenge, new_password } */
    public function complete(Request $request)
    {
        $data = $request->validate([
            'challenge' => 'required|string',
            'new_password' => ['required', 'string', new PasswordPolicy()],
        ]);
        $req = $this->liveChallenge($data['challenge']);
        $agent = $req ? Agent::find($req->agent_id) : null;
        if (!$req || !$req->verified_at || !$agent || $agent->status !== 'active') {
            return response()->json(['message' => 'That reset link expired. Start over.'], 422);
        }
        if ($err = PasswordPolicyService::reuseError($agent, PasswordHistory::TYPE_AGENT, $data['new_password'])) {
            return response()->json(['message' => $err], 422);
        }
        PasswordPolicyService::change($agent, PasswordHistory::TYPE_AGENT, $data['new_password'],
            PasswordPolicyService::T_FORGOT, [
                'domain'     => $agent->domain,
                'actor_type' => 'agent',
                'actor_id'   => $agent->id,
                'actor_name' => trim($agent->first_name . ' ' . $agent->last_name),
                'ip'         => $request->ip(),
            ]);
        $req->update(['completed_at' => now()]);
        return response()->json(['ok' => true]);
    }

    protected function liveChallenge(string $plain): ?PasswordResetRequest
    {
        return PasswordResetRequest::where('token_hash', hash('sha256', $plain))
            ->whereNull('completed_at')
            ->where('created_at', '>=', now()->subSeconds(self::TTL_SECS))
            ->first();
    }
}

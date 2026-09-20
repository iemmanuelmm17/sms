<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Http\Controllers\Concerns\ResolvesActor;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\PasswordHistory;
use App\Rules\PasswordPolicy;
use App\Services\PasswordPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** The logged-in agent's own profile: color (presets + custom hex) + password + ping. */
class AgentSelfController extends Controller
{
    use ResolvesActor;

    public const PALETTE = ['#6366f1', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16'];

    protected function self(Request $r): Agent
    {
        $a = $this->actor($r);
        abort_unless($a['role'] === 'agent', 403, 'Agents only.');
        return Agent::findOrFail($a['agent_id']);
    }

    /** GET /api/agent/profile */
    public function show(Request $request)
    {
        return response()->json($this->self($request));
    }

    /** PATCH /api/agent/profile { tag_color } */
    public function update(Request $request)
    {
        $agent = $this->self($request);
        $data = $request->validate(['tag_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        $agent->update($data);
        DataChanged::send($agent->domain, $agent->user, 'agents', 'saved', $agent->id);
        \App\Services\OnboardingService::mark($agent, 'tag');
        return response()->json($agent->fresh());
    }

    /** POST /api/agent/password — gated by the agent's CURRENT password. */
    public function password(Request $request)
    {
        $agent = $this->self($request);
        $data = $request->validate([
            'current_password' => 'required|string',
            'new_password'     => ['required', 'string', new PasswordPolicy()],
            'confirm_password' => 'required|string',
        ]);
        if ($data['new_password'] !== $data['confirm_password']) {
            return response()->json(['message' => 'Passwords do not match.'], 422);
        }
        if (!$agent->password_hash || !Hash::check($data['current_password'], $agent->password_hash)) {
            return response()->json(['message' => 'Current password is incorrect.'], 403);
        }
        if ($err = PasswordPolicyService::reuseError($agent, PasswordHistory::TYPE_AGENT, $data['new_password'])) {
            return response()->json(['message' => $err], 422);
        }
        PasswordPolicyService::change($agent, PasswordHistory::TYPE_AGENT, $data['new_password'],
            PasswordPolicyService::T_VOLUNTARY, [
                'domain'     => $agent->domain,
                'actor_type' => 'agent',
                'actor_id'   => $agent->id,
                'actor_name' => trim($agent->first_name . ' ' . $agent->last_name),
                'ip'         => $request->ip(),
            ]);
        // Stay logged in HERE; every other session drops on next request.
        $request->session()->put('agent.v', $agent->session_version);
        return response()->json(['ok' => true]);
    }

    /** POST /api/agent/ping — presence heartbeat (drives the logged-in pill). */
    public function ping(Request $request)
    {
        $agent = $this->self($request);
        $agent->forceFill(['last_seen_at' => now()])->save();
        return response()->json(['ok' => true]);
    }
}

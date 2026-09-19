<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Http\Controllers\Concerns\ResolvesActor;
use App\Models\Agent;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/** The logged-in agent's own profile: color (fixed palette) + password + ping. */
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
        $data = $request->validate(['tag_color' => ['required', Rule::in(self::PALETTE)]]);
        $agent->update($data);
        DataChanged::send($agent->domain, $agent->user, 'agents', 'saved', $agent->id);
        return response()->json($agent->fresh());
    }

    /** POST /api/agent/password — gated by the agent's CURRENT password. */
    public function password(Request $request)
    {
        $agent = $this->self($request);
        $data = $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|max:200',
        ]);
        if (!$agent->password_hash || !Hash::check($data['current_password'], $agent->password_hash)) {
            return response()->json(['message' => 'Current password is incorrect.'], 403);
        }
        $agent->update([
            'password_hash' => Hash::make($data['new_password']),
            'session_version' => $agent->session_version + 1,
        ]);
        // Stay logged in HERE; every other session drops on next request.
        $request->session()->put('agent.v', $agent->session_version);
        AuditLog::record($agent->domain, 'agent', $agent->id,
            trim($agent->first_name . ' ' . $agent->last_name),
            'agent.password-changed', [], $request->ip());
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

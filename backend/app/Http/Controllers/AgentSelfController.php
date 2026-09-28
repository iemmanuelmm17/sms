<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Http\Controllers\Concerns\ResolvesActor;
use App\Models\Agent;
use App\Models\AgentIdentity;
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

    /**
     * The signed-in agent, as either model.
     *
     * Portal agents have NO legacy Agent row (agent_id is null), so
     * Agent::findOrFail() returned 404 here — that is what broke colour
     * assignment, the presence heartbeat and the profile fetch.
     */
    protected function self(Request $r): Agent|AgentIdentity
    {
        $a = $this->actor($r);
        abort_unless($a['role'] === 'agent', 403, 'Agents only.');
        if (!empty($a['identity_id'])) return AgentIdentity::findOrFail($a['identity_id']);
        abort_unless(!empty($a['agent_id']), 403, 'Agents only.');
        return Agent::findOrFail($a['agent_id']);
    }

    /** GET /api/agent/profile */
    public function show(Request $request)
    {
        $me = $this->self($request);
        if ($me instanceof AgentIdentity) {
            return response()->json([
                'id' => $me->id, 'ext' => $me->ext, 'domain' => $me->domain,
                'display_name' => $me->displayName(),
                'tag_color' => $me->tag_color, 'status' => $me->status,
                'portal_auth' => true,
            ]);
        }
        return response()->json($me);
    }

    /** PATCH /api/agent/profile { tag_color } — works for both models. */
    public function update(Request $request)
    {
        $me = $this->self($request);
        $data = $request->validate(['tag_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        $me->update($data);
        \App\Services\OnboardingService::mark($me, 'tag');

        if ($me instanceof AgentIdentity) {
            // Broadcast to the shared room for the domain: that is what all
            // portal agents (any extension) and admins listen on.
            // DataChanged::send resolves the room from the domain.
            DataChanged::send($me->domain, $me->ext, 'agents', 'saved', $me->id);
            return response()->json([
                'id' => $me->id, 'ext' => $me->ext, 'domain' => $me->domain,
                'display_name' => $me->displayName(),
                'tag_color' => $me->fresh()->tag_color, 'portal_auth' => true,
            ]);
        }
        DataChanged::send($me->domain, $me->user, 'agents', 'saved', $me->id);
        return response()->json($me->fresh());
    }

    /** POST /api/agent/password — gated by the agent's CURRENT password. */
    public function password(Request $request)
    {
        $agent = $this->self($request);
        if ($agent instanceof AgentIdentity) {
            return response()->json([
                'message' => 'Your password is managed by the Dynalink portal. Change it there.',
            ], 400);
        }
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
        $me = $this->self($request);
        $me->forceFill(['last_seen_at' => now()])->save();
        return response()->json(['ok' => true]);
    }
}

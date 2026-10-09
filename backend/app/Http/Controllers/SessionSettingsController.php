<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesActor;
use App\Models\Agent;
use App\Models\AgentIdentity;
use App\Models\AuditLog;
use App\Models\TenantAdmin;
use App\Services\IdleTimeout;
use Illuminate\Http\Request;

/**
 * The signed-in user's own sign-in preferences (avatar menu > Session timeout).
 *
 * Writes only the caller's own row — the identity is resolved from the
 * session by ResolvesActor, never from the request body.
 */
class SessionSettingsController extends Controller
{
    use ResolvesActor;

    /** PUT /api/me/idle-timeout { idle_timeout_hours: 0..8 } (0 = unlimited) */
    public function updateIdleTimeout(Request $request)
    {
        $actor = $this->actor($request);

        $data = $request->validate([
            'idle_timeout_hours' => ['required', 'integer', 'in:' . implode(',', IdleTimeout::options())],
        ]);
        $hours = (int) $data['idle_timeout_hours'];

        // Break-glass Dynalink sessions have no local row to store a choice on.
        $user = match (true) {
            !empty($actor['tenant_admin_id']) => TenantAdmin::find($actor['tenant_admin_id']),
            !empty($actor['identity_id'])     => AgentIdentity::find($actor['identity_id']),
            !empty($actor['agent_id'])        => Agent::find($actor['agent_id']),
            default                           => null,
        };
        if (!$user) {
            return response()->json([
                'message' => 'Session timeout is managed by your Dynalink portal login for this account.',
            ], 422);
        }

        $user->forceFill(['idle_timeout_hours' => $hours])->save();

        AuditLog::record($actor['domain'] ?? null, $actor['role'] ?? 'unknown', $actor['agent_id'] ?? $actor['identity_id'] ?? $actor['tenant_admin_id'] ?? null,
            $actor['display_name'] ?? null, 'user.idle-timeout.update',
            ['hours' => $hours === IdleTimeout::UNLIMITED ? 'unlimited' : $hours], $request->ip());

        return response()->json(['ok' => true, 'idle_timeout_hours' => $hours]);
    }
}

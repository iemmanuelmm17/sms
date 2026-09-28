<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesActor;
use App\Models\Agent;
use App\Models\TenantAdmin;
use App\Services\OnboardingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The logged-in user's own onboarding state (agents + tenant admins only). */
class OnboardingController extends Controller
{
    use ResolvesActor;

    /** PUT /api/me/onboarding { step?, welcomed?, tour_seen?, dismissed? } */
    public function update(Request $request)
    {
        $actor = $this->actor($request); // 401 unless a session is valid
        if (($actor['role'] ?? '') === 'agent' && !empty($actor['identity_id'])) {
            // Portal agents have no legacy Agent row — Agent::findOrFail(null)
            // was returning 404 here, which silently broke the onboarding
            // Skip / Get started buttons.
            $model = \App\Models\AgentIdentity::findOrFail($actor['identity_id']);
            $role = 'agent';
        } elseif (($actor['role'] ?? '') === 'agent' && !empty($actor['agent_id'])) {
            $model = Agent::findOrFail($actor['agent_id']);
            $role = 'agent';
        } elseif (!empty($actor['tenant_admin_id'])) {
            $model = TenantAdmin::findOrFail($actor['tenant_admin_id']);
            $role = 'admin';
        } else {
            abort(403, 'Onboarding is available for agents and tenant admins.');
        }
        $data = $request->validate([
            'step' => ['sometimes', 'string', Rule::in(OnboardingService::stepsFor($role))],
            'welcomed' => 'sometimes|boolean',
            'tour_seen' => 'sometimes|boolean',
            'dismissed' => 'sometimes|boolean',
        ]);
        if (isset($data['step'])) OnboardingService::mark($model, $data['step']);
        $flags = array_intersect_key($data, array_flip(OnboardingService::FLAGS));
        if ($flags !== []) OnboardingService::flags($model, $flags);
        return response()->json(['onboarding' => OnboardingService::state($model)]);
    }
}

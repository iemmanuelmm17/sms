<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentIdentity;
use App\Models\TenantAdmin;

/**
 * First-run onboarding state (one JSON column per user, no new tables).
 * Agents: installed → push → tag → first_send.
 * Tenant admins: installed → push → agent_created → numbers_reviewed.
 * `done` is DERIVED (every role step stamped), never stored.
 * Portal agents (AgentIdentity) use the same agent steps.
 * Legacy Dynalink (break-glass) sessions have no row → no onboarding.
 */
class OnboardingService
{
    public const AGENT_STEPS = ['installed', 'push', 'tag', 'first_send'];
    public const ADMIN_STEPS = ['installed', 'push', 'agent_created', 'numbers_reviewed'];
    public const FLAGS = ['welcomed', 'tour_seen', 'dismissed'];

    public static function stepsFor(string $role): array
    {
        return $role === 'agent' ? self::AGENT_STEPS : self::ADMIN_STEPS;
    }

    public static function roleOf(Agent|AgentIdentity|TenantAdmin $model): string
    {
        return ($model instanceof Agent || $model instanceof AgentIdentity) ? 'agent' : 'admin';
    }

    /** Normalized state for the /me payload and the PUT response. */
    public static function state(Agent|AgentIdentity|TenantAdmin $model): array
    {
        $raw = is_array($model->onboarding) ? $model->onboarding : [];
        $want = self::stepsFor(self::roleOf($model));
        $steps = array_intersect_key((array) ($raw['steps'] ?? []), array_flip($want));
        return [
            'done' => count($steps) >= count($want),
            'dismissed' => (bool) ($raw['dismissed'] ?? false),
            'welcomed' => (bool) ($raw['welcomed'] ?? false),
            'tour_seen' => (bool) ($raw['tour_seen'] ?? false),
            'steps' => $steps,
        ];
    }

    /** Stamp one step (idempotent); returns fresh state. Unknown steps ignored. */
    public static function mark(Agent|AgentIdentity|TenantAdmin $model, string $step): array
    {
        if (!in_array($step, self::stepsFor(self::roleOf($model)), true)) return self::state($model);
        $raw = is_array($model->onboarding) ? $model->onboarding : [];
        $steps = (array) ($raw['steps'] ?? []);
        if (!isset($steps[$step])) {
            $steps[$step] = now()->toISOString();
            $raw['steps'] = $steps;
            $model->forceFill(['onboarding' => $raw])->save();
        }
        return self::state($model);
    }

    /** Set welcome/tour/dismiss flags; returns fresh state. */
    public static function flags(Agent|AgentIdentity|TenantAdmin $model, array $flags): array
    {
        $raw = is_array($model->onboarding) ? $model->onboarding : [];
        foreach (self::FLAGS as $f) {
            if (array_key_exists($f, $flags)) $raw[$f] = (bool) $flags[$f];
        }
        $model->forceFill(['onboarding' => $raw])->save();
        return self::state($model);
    }

    /** Send-path helper: stamps $step when the actor is an agent (silent otherwise). */
    public static function markAgentStep(array $actor, string $step): void
    {
        try {
            if (($actor['role'] ?? '') !== 'agent') return;
            // Portal agents carry identity_id; legacy agents carry agent_id.
            $model = !empty($actor['identity_id'])
                ? AgentIdentity::find($actor['identity_id'])
                : (!empty($actor['agent_id']) ? Agent::find($actor['agent_id']) : null);
            if ($model) self::mark($model, $step);
        } catch (\Throwable $e) { /* onboarding must never break the hot path */ }
    }

    /** Same for tenant-admin actors (legacy admins have no row → skip). */
    public static function markAdminStep(array $actor, string $step): void
    {
        try {
            if (empty($actor['tenant_admin_id'])) return;
            $admin = TenantAdmin::find($actor['tenant_admin_id']);
            if ($admin) self::mark($admin, $step);
        } catch (\Throwable $e) { /* onboarding must never break the hot path */ }
    }
}

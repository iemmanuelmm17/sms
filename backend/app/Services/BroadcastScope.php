<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Tenant;
use App\Models\TenantAdmin;

/**
 * Single source of truth for "which Dynalink scope does this session listen to".
 * Used by BOTH the broadcast user resolver (ResolveBroadcastUser middleware)
 * and the channel authorization (routes/channels.php) — they must always agree,
 * otherwise subscriptions 403.
 *
 * Legacy dynalink sessions pass through untouched; tenant/agent sessions resolve
 * their Dynalink scope and must still be active with a current session version.
 */
class BroadcastScope
{
    /**
     * The shared realtime channel scope for a Dynalink domain.
     *
     * A tenant-managed domain is ONE room: the tenant admin, every portal
     * agent (each living on their own extension user) and every webhook
     * event must all land on the same channel or nobody hears anybody.
     * So on tenant domains every user collapses to the tenant's Dynalink
     * user. Domains without a tenant row (legacy Dynalink) keep the user
     * as-is — it is already the shared scope for that account.
     *
     * @return array{0: string, 1: string} [domain, user]
     */
    public static function scopeFor(string $domain, string $user): array
    {
        $tenant = Tenant::where('domain', $domain)->first();
        if ($tenant && $tenant->isActive() && $tenant->dynalink_user) {
            return [$tenant->domain, $tenant->dynalink_user];
        }
        return [$domain, $user];
    }

    public static function fromSession($session): ?array
    {
        if ($s = $session->get('dynalink')) {
            if (!empty($s['domain']) && !empty($s['user'])) {
                // Legacy break-glass sessions are keyed by the session's own
                // Dynalink login; on a tenant-managed domain remap onto the
                // one shared tenant channel (no-op elsewhere).
                [$d, $u] = self::scopeFor((string) $s['domain'], (string) $s['user']);
                return ['domain' => $d, 'user' => $u, 'via' => 'dynalink'];
            }
        }
        if ($t = $session->get('tenant')) {
            $admin = TenantAdmin::with('tenant')->find($t['id'] ?? null);
            if ($admin && $admin->isActive() && $admin->tenant && $admin->tenant->isActive()
                && (int) $admin->session_version === (int) ($t['v'] ?? 0)) {
                // Already the tenant anchor — the channel everyone else joins.
                return ['domain' => $admin->tenant->domain, 'user' => $admin->tenant->dynalink_user, 'via' => 'tenant'];
            }
        }
        if ($p = $session->get('agent_portal')) {
            $identity = \App\Models\AgentIdentity::find($p['id'] ?? null);
            if ($identity && $identity->isActive()) {
                // Portal agents listen on the TENANT scope, because shared
                // threads are owned by other extensions — listening on their
                // own extension would miss every shared-number event.
                $tenant = \App\Models\Tenant::where('domain', $identity->domain)->first();
                if ($tenant && $tenant->isActive()) {
                    return ['domain' => $tenant->domain, 'user' => $tenant->dynalink_user, 'via' => 'agent_portal'];
                }
            }
            return null;
        }
        if ($a = $session->get('agent')) {
            $agent = Agent::find($a['id'] ?? null);
            if ($agent && ($agent->status ?: 'active') === 'active'
                && (int) $agent->session_version === (int) ($a['v'] ?? 0)) {
                // Legacy agents are keyed by their owner's Dynalink login;
                // on a tenant-managed domain remap onto the tenant channel
                // (no-op elsewhere).
                [$d, $u] = self::scopeFor((string) $agent->domain, (string) $agent->user);
                return ['domain' => $d, 'user' => $u, 'via' => 'agent'];
            }
        }
        return null;
    }
}

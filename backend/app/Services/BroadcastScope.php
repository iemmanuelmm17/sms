<?php

namespace App\Services;

use App\Models\Agent;
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
     * The one shared realtime room per Dynalink domain.
     *
     * Channel name: private-sms.{sanitized domain}.{ROOM}
     *
     * The Dynalink user/extension is deliberately NOT part of the channel:
     * a domain's admin and every agent (each on a different user extension)
     * must all sit in the same room, or they could never hear each other.
     */
    public const ROOM = 'shared';

    /**
     * The realtime channel target for a domain: the shared domain room.
     * Every broadcaster (DataChanged mutations, inbound webhook events)
     * must use this so all participants of the domain hear everything.
     *
     * @return array{0: string, 1: string} [domain, room]
     */
    public static function scopeFor(string $domain): array
    {
        return [$domain, self::ROOM];
    }

    public static function fromSession($session): ?array
    {
        if ($s = $session->get('dynalink')) {
            if (!empty($s['domain']) && !empty($s['user'])) {
                return ['domain' => (string) $s['domain'], 'user' => (string) $s['user'], 'via' => 'dynalink'];
            }
        }
        if ($t = $session->get('tenant')) {
            $admin = TenantAdmin::with('tenant')->find($t['id'] ?? null);
            if ($admin && $admin->isActive() && $admin->tenant && $admin->tenant->isActive()
                && (int) $admin->session_version === (int) ($t['v'] ?? 0)) {
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
                return ['domain' => $agent->domain, 'user' => $agent->user, 'via' => 'agent'];
            }
        }
        return null;
    }
}

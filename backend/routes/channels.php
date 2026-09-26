<?php

use App\Services\BroadcastScope;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

/*
 | One private realtime channel per Dynalink domain scope:
 |   private-sms.{safe-domain}.{safe-scope-user}
 |
 | Tenant-managed domains are ONE room: BroadcastScope::scopeFor() collapses
 | the tenant admin, every portal agent (each on their own extension) and
 | legacy break-glass sessions onto the tenant's Dynalink user, and every
 | broadcaster (DataChanged, webhook) targets that same channel. The
 | subscriber's channel user must equal their resolved scope user, so all
 | participants of a domain hear every mutation and inbound message.
 |
 | Dots in Dynalink domains can never match a {param} segment, so the
 | broadcasters and the subscriber sanitize identically: [^A-Za-z0-9-] → "_".
 */
Broadcast::channel('sms.{domain}.{user}', function ($user, $domain, $userParam) {
    $scope = BroadcastScope::fromSession(session());
    if (! $scope) {
        return false;
    }

    $safe = fn ($v) => preg_replace('/[^A-Za-z0-9-]/', '_', (string) $v);
    $domainMatches = $safe($scope['domain']) === $domain;

    // Legacy: pre-fix agent builds subscribed to a 'shared' room token.
    // They authorise (so upgrades degrade gracefully) but no broadcasts
    // target that channel any more — the shared room is the scope user.
    if ($userParam === 'shared' && $scope['via'] === 'agent_portal') {
        return $domainMatches;
    }

    // Default: the channel user must be the session's resolved scope user
    // (the tenant anchor on tenant domains — see BroadcastScope).
    return $domainMatches && ($safe($scope['user']) === (string) $userParam);
});

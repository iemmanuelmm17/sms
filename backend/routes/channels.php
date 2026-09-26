<?php

use App\Services\BroadcastScope;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

/*
 | Private realtime channel per Dynalink account:
 |   private-sms.{safe-domain}.{safe-user}
 | Dots in Dynalink domains can never match a {param} segment, so the
 | broadcasters and the subscriber sanitize identically: [^A-Za-z0-9-] → "_".
 | Only a session whose Dynalink scope matches may listen — legacy Dynalink,
 | tenant-admin, and agent sessions all resolve via BroadcastScope.
 */
Broadcast::channel('sms.{domain}.{user}', function ($user, $domain, $userParam) {
    $scope = BroadcastScope::fromSession(session());
    if (! $scope) {
        return false;
    }
    
    $safe = fn ($v) => preg_replace('/[^A-Za-z0-9-]/', '_', (string) $v);
    $domainMatches = $safe($scope['domain']) === $domain;

    // 1. Explicitly check if agents are connecting via the 'shared' room token
    if ($userParam === 'shared' && $scope['via'] === 'agent_portal') {
        return $domainMatches; // Authorize if they match the domain
    }

    // 2. Default legacy matching rule for non-agents
    return $domainMatches && ($safe($scope['user']) === (string) $userParam);
});



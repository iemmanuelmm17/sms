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
    $ok = $safe($scope['domain']) === $domain
        && $safe($scope['user']) === (string) $userParam;
    Log::info('broadcast-auth attempt', [
        'channel_domain' => $domain,
        'channel_user' => $userParam,
        'via' => $scope['via'],
        'ok' => $ok,
    ]);
    return $ok;
});

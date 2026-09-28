<?php

use App\Services\BroadcastScope;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

/*
 | Realtime channels per Dynalink domain:
 |   private-sms.{safe-domain}.{room}
 |
 | The room is SHARED PER DOMAIN — private-sms.{safe-domain}.shared — and
 | every participant of the domain (tenant admin, every portal agent on
 | their own user extension, legacy sessions) joins it. All broadcasts
 | (DataChanged mutations, inbound webhook events) target that same room
 | via BroadcastScope::scopeFor(), so agents hear each other and the
 | admin in realtime regardless of which extension owns a conversation
 | or a number.
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
    if (! $domainMatches) {
        return false;
    }

    // The shared domain room: any valid session on this domain may join.
    if ($userParam === BroadcastScope::ROOM) {
        return true;
    }

    // Legacy: pre-unification clients subscribed to the per-user channel
    // (sms.{domain}.{own user}). They still authorise so upgrades degrade
    // gracefully, but broadcasts no longer target that channel.
    return $safe($scope['user']) === (string) $userParam;
});

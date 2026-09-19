<?php

namespace App\Http\Middleware;

use App\Services\BroadcastScope;
use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ResolveBroadcastUser
{
    /**
     * Expose the portal session login as the request's "user".
     *
     * Laravel's PusherBroadcaster::auth() throws a 403 for private/presence
     * channels when it cannot retrieve a user — before routes/channels.php
     * callbacks ever run. This app keeps login state in the session
     * (dynalink / tenant / agent keys, not Auth guards), so without this
     * middleware every POST /broadcasting/auth fails with zero callback logs.
     *
     * Both resolution paths are covered: $request->user() callers via the
     * user resolver, and Auth-guard callers via guard setUser() (guards are
     * set per-request from the session — no user provider needed).
     */
    public function handle(Request $request, Closure $next)
    {
        $scope = BroadcastScope::fromSession($request->session());

        if ($scope) {
            $user = new GenericUser([
                'id' => $scope['domain'] . '|' . $scope['user'],
                'domain' => $scope['domain'],
                'user' => $scope['user'],
            ]);

            $request->setUserResolver(fn () => $user);

            Auth::guard('web')->setUser($user);
        }

        return $next($request);
    }
}

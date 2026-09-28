<?php

namespace App\Http\Middleware;

use App\Services\BroadcastScope;
use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ResolveBroadcastUser
{
    public function handle(Request $request, Closure $next)
    {
        // 1. Instantly handle browser CORS preflight (OPTIONS) requests
        if ($request->isMethod('OPTIONS')) {
            return response('', 204)
                ->header('Access-Control-Allow-Origin', 'http://localhost:5173')
                ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With, Authorization, X-CSRF-TOKEN')
                ->header('Access-Control-Allow-Credentials', 'true');
        }

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

        $response = $next($request);

        // 2. Attach cross-origin headers to the final successful auth response
        if ($request->is('broadcasting/auth') && method_exists($response, 'header')) {
            $response->header('Access-Control-Allow-Origin', 'http://localhost:5173');
            $response->header('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}

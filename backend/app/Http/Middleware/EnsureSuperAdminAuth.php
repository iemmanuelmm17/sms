<?php

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use Closure;
use Illuminate\Http\Request;

/** Session guard for the superadmin API (separate `superadmin` session key). */
class EnsureSuperAdminAuth
{
    public function handle(Request $request, Closure $next)
    {
        $s = $request->session()->get('superadmin');
        $sa = $s ? SuperAdmin::find($s['id'] ?? null) : null;
        if (!$sa || !$sa->isActive() || (int) $sa->session_version !== (int) ($s['v'] ?? 0)) {
            $request->session()->forget('superadmin');
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        if ($sa->must_change_password
            && !$request->is('api/superadmin/me', 'api/superadmin/logout', 'api/superadmin/password')) {
            return response()->json(['message' => 'Password change required.',
                'must_change_password' => true], 403);
        }
        $request->attributes->set('superadmin', $sa);
        return $next($request);
    }
}

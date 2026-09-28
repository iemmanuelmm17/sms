<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SuperAdmin;
use App\Services\LockoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** Superadmin session auth. Login is IP-gated; logout forgets only its own key. */
class SuperAdminAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:60',
            'password' => 'required|string',
        ]);
        $key = 'superadmin:' . mb_strtolower(trim($data['username']));
        if ($secs = LockoutService::remainingFor($key)) {
            AuditLog::record(null, 'unknown', null, $key, 'superadmin.login.locked', [], $request->ip());
            return response()->json(['message' => 'Too many failed attempts. Try again in ' . gmdate('i:s', $secs) . '.',
                'retry_after_secs' => $secs], 423);
        }
        if (LockoutService::ipLocked($request->ip())) {
            AuditLog::record(null, 'unknown', null, $key, 'ip.login-blocked', [], $request->ip());
            return response()->json(['message' => 'Too many failed attempts. Try again in 05:00.',
                'retry_after_secs' => 300], 423);
        }
        $sa = SuperAdmin::where('username', mb_strtolower(trim($data['username'])))->first();
        if (!$sa || !$sa->isActive() || !Hash::check($data['password'], $sa->password_hash)) {
            LockoutService::record($key, $request->ip(), false);
            AuditLog::record(null, 'unknown', null, $key, 'superadmin.login.fail', [], $request->ip());
            return response()->json(['message' => 'Incorrect username or password.'], 401);
        }
        $request->session()->regenerate();
        $request->session()->put('superadmin', ['id' => $sa->id, 'v' => $sa->session_version]);
        $sa->forceFill(['last_seen_at' => now()])->save();
        LockoutService::record($key, $request->ip(), true);
        AuditLog::record(null, 'superadmin', null, $sa->username, 'superadmin.login.success', [], $request->ip());
        return response()->json(['user' => $this->payload($sa->fresh())]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $this->payload($request->attributes->get('superadmin'))]);
    }

    public function logout(Request $request)
    {
        if ($sa = $request->attributes->get('superadmin')) {
            AuditLog::record(null, 'superadmin', null, $sa->username, 'superadmin.logout', [], $request->ip());
        }
        $request->session()->forget('superadmin');
        return response()->json(['ok' => true]);
    }

    /** POST /api/superadmin/password — gated by the CURRENT password. */
    public function password(Request $request)
    {
        $sa = $request->attributes->get('superadmin');
        $data = $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|max:200',
        ]);
        if (!Hash::check($data['current_password'], $sa->password_hash)) {
            return response()->json(['message' => 'Current password is incorrect.'], 403);
        }
        $sa->update([
            'password_hash' => Hash::make($data['new_password']),
            'session_version' => $sa->session_version + 1,
            'must_change_password' => false,
        ]);
        // Stay logged in HERE; every other session drops on next request.
        $request->session()->put('superadmin.v', $sa->session_version);
        AuditLog::record(null, 'superadmin', null, $sa->username,
            'superadmin.password-changed', [], $request->ip());
        return response()->json(['ok' => true]);
    }

    protected function payload(SuperAdmin $sa): array
    {
        return [
            'role' => 'superadmin', 'id' => $sa->id, 'username' => $sa->username,
            'display_name' => $sa->displayName(),
            'must_change_password' => (bool) $sa->must_change_password,
        ];
    }
}

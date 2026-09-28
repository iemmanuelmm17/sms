<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesActor;
use App\Models\PasswordHistory;
use App\Models\TenantAdmin;
use App\Rules\PasswordPolicy;
use App\Services\PasswordPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * The signed-in tenant admin's own password (avatar menu > Change Password).
 *
 * Runs the same shared PasswordPolicy + reuse check as every other path;
 * the only difference is the audit trigger (voluntary).
 */
class TenantSelfController extends Controller
{
    use ResolvesActor;

    protected function self(Request $r): TenantAdmin
    {
        $a = $this->actor($r);
        abort_unless($a['role'] === 'admin', 403, 'Admins only.');

        $id = $a['tenant_admin_id'] ?? null;
        if (!$id) {
            // Legacy break-glass Dynalink session: no local password row to rotate.
            abort(response()->json([
                'message' => 'Password changes are not available for Dynalink direct logins.',
            ], 422));
        }
        return TenantAdmin::findOrFail($id);
    }

    /** POST /api/tenant/password { current_password, new_password, confirm_password } */
    public function password(Request $request)
    {
        $admin = $this->self($request);

        $data = $request->validate([
            'current_password' => 'required|string',
            'new_password'     => ['required', 'string', new PasswordPolicy()],
            'confirm_password' => 'required|string',
        ]);

        if ($data['new_password'] !== $data['confirm_password']) {
            return response()->json(['message' => 'Passwords do not match.'], 422);
        }
        if (!$admin->password_hash || !Hash::check($data['current_password'], $admin->password_hash)) {
            return response()->json(['message' => 'Current password is incorrect.'], 403);
        }
        if ($err = PasswordPolicyService::reuseError($admin, PasswordHistory::TYPE_ADMIN, $data['new_password'])) {
            return response()->json(['message' => $err], 422);
        }

        PasswordPolicyService::change($admin, PasswordHistory::TYPE_ADMIN, $data['new_password'],
            PasswordPolicyService::T_VOLUNTARY, [
                'domain'     => $admin->tenant->domain ?? null,
                'actor_type' => 'admin',
                'actor_id'   => $admin->id,
                'actor_name' => $admin->displayName(),
                'ip'         => $request->ip(),
            ]);

        // change() bumps session_version to drop every OTHER session; re-seat
        // this one so the admin isn't signed out by their own password change.
        $request->session()->put('tenant', ['id' => $admin->id, 'v' => $admin->session_version]);

        return response()->json(['ok' => true]);
    }
}

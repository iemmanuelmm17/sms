<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesActor;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\PasswordPolicyService;
use Illuminate\Http\Request;

/**
 * Per-tenant password-expiry window (Admin settings page).
 *
 * Editing this only affects passwords set from that moment on. Users already
 * mid-cycle keep the day count stored on their own row in
 * password_expiry_days_applied — see PasswordPolicyService::change().
 */
class TenantSettingsController extends Controller
{
    use ResolvesActor;

    /** GET /api/settings/password-expiry */
    public function show(Request $request)
    {
        [$tenant] = $this->tenantOrFail($request);

        return response()->json([
            'days'    => PasswordPolicyService::expiryDaysFor($tenant),
            'min'     => PasswordPolicyService::MIN_EXPIRY_DAYS,
            'max'     => PasswordPolicyService::MAX_EXPIRY_DAYS,
            'default' => PasswordPolicyService::DEFAULT_EXPIRY_DAYS,
            'note'    => 'Applies the next time a user changes their password. '
                . "It won't affect passwords already in progress.",
        ]);
    }

    /** PUT /api/settings/password-expiry { days } */
    public function update(Request $request)
    {
        [$tenant, $actor] = $this->tenantOrFail($request);

        $data = $request->validate([
            'days' => ['required', 'integer',
                'min:' . PasswordPolicyService::MIN_EXPIRY_DAYS,
                'max:' . PasswordPolicyService::MAX_EXPIRY_DAYS],
        ], [
            'days.required' => 'Enter a number of days.',
            'days.integer'  => 'Enter a whole number of days.',
            'days.min'      => 'Password expiry must be at least '
                . PasswordPolicyService::MIN_EXPIRY_DAYS . ' day.',
            'days.max'      => 'Password expiry cannot be more than '
                . PasswordPolicyService::MAX_EXPIRY_DAYS . ' days.',
        ]);

        $before = PasswordPolicyService::expiryDaysFor($tenant);
        $after  = (int) $data['days'];

        $tenant->forceFill(['password_expiry_days' => $after])->save();

        AuditLog::record($tenant->domain, 'admin', null, $actor['username'] ?? null,
            'tenant.password-expiry.updated', [
                'from' => $before, 'to' => $after, 'tenant_id' => $tenant->id,
            ], $request->ip());

        return response()->json([
            'ok'     => true,
            'days'   => $after,
            'note'   => 'This will apply the next time a user changes their password. '
                . "It won't affect passwords already in progress.",
        ]);
    }

    /** @return array{0: Tenant, 1: array} */
    protected function tenantOrFail(Request $request): array
    {
        $actor = $this->actor($request);
        $this->requireAdmin($actor);

        $tenant = !empty($actor['tenant_id'])
            ? Tenant::find($actor['tenant_id'])
            : null;

        // Legacy Dynalink session: resolve the tenant by data scope instead.
        if (!$tenant && !empty($actor['domain']) && !empty($actor['user'])) {
            $tenant = Tenant::where('domain', $actor['domain'])
                ->where('dynalink_user', $actor['user'])->first();
        }

        if (!$tenant) {
            abort(response()->json(['message' => 'No tenant is linked to this account.'], 422));
        }
        return [$tenant, $actor];
    }
}

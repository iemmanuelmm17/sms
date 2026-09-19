<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\LockoutService;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;

/** Admin lockout panel: list + unlock usernames/IPs. */
class LockoutController extends Controller
{
    use ResolvesActor;

    /** GET /api/lockouts — active lockouts for the admin's tenant. */
    public function index(Request $request)
    {
        $this->requireAdmin($request);
        return response()->json(LockoutService::lockedForDomain($this->actor($request)['domain']));
    }

    /** POST /api/lockouts/users/unblock { username } — own tenant only. */
    public function unblockUser(Request $request)
    {
        $this->requireAdmin($request);
        $actor = $this->actor($request);
        $data = $request->validate(['username' => 'required|string|max:160']);
        $key = mb_strtolower(trim($data['username']));
        abort_unless(str_ends_with($key, '@' . mb_strtolower($actor['domain'])), 403, 'You can only unlock your own tenant.');
        $n = LockoutService::clearUser($key);
        AuditLog::record($actor['domain'], 'admin', null, $actor['user'] . '@' . $actor['domain'],
            'admin.user-unblocked', ['username' => $key, 'cleared' => $n], $request->ip());
        return response()->json(['ok' => true, 'cleared' => $n]);
    }

    /** POST /api/lockouts/ips/unblock { ip } — clears globally (the backstop counts globally). */
    public function unblockIp(Request $request)
    {
        $this->requireAdmin($request);
        $actor = $this->actor($request);
        $data = $request->validate(['ip' => 'required|string|max:60']);
        $ip = trim($data['ip']);
        $n = LockoutService::clearIp($ip);
        AuditLog::record($actor['domain'], 'admin', null, $actor['user'] . '@' . $actor['domain'],
            'admin.ip-unblocked', ['ip' => $ip, 'cleared' => $n], $request->ip());
        return response()->json(['ok' => true, 'cleared' => $n]);
    }
}

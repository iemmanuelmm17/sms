<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;

/** Web Push subscription management (agents + tenant admins, session auth). */
class PushSubscriptionController extends Controller
{
    use ResolvesActor;

    protected function sess(Request $r): array
    {
        return $this->actor($r);
    }

    /** GET /api/push/vapid-key */
    public function vapidKey(Request $request)
    {
        $this->sess($request);
        return response()->json(['key' => (string) config('services.webpush.public_key', '')]);
    }

    /** POST /api/push/subscriptions { endpoint, keys: { p256dh, auth } } */
    public function store(Request $request)
    {
        $s = $this->sess($request);
        $data = $request->validate([
            'endpoint' => 'required|url|max:1000',
            'keys.p256dh' => 'required|string|max:300',
            'keys.auth' => 'required|string|max:100',
        ]);
        $agentId = ($s['role'] ?? '') === 'agent' ? ($s['agent_id'] ?? null) : null;
        $adminId = null;
        if (($s['role'] ?? '') !== 'agent') {
            $t = $request->session()->get('tenant');
            $adminId = $t['id'] ?? null;
        }
        PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            ['agent_id' => $agentId, 'tenant_admin_id' => $adminId,
                'domain' => $s['domain'], 'user' => $s['user'],
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth']]
        );
        return response()->json(['ok' => true]);
    }

    /** DELETE /api/push/subscriptions { endpoint } */
    public function destroy(Request $request)
    {
        $s = $this->sess($request);
        $data = $request->validate(['endpoint' => 'required|string|max:1000']);
        PushSubscription::where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->where('domain', $s['domain'])->where('user', $s['user'])->delete();
        return response()->json(['ok' => true]);
    }
}

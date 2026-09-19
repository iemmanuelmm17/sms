<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverTenantWebhook;
use App\Models\TenantWebhook;
use App\Models\WebhookDelivery;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Portal management for tenant outbound webhooks (admin-only). */
class TenantWebhookController extends Controller
{
    use ResolvesActor;

    protected function sess(Request $r): array
    {
        return $this->actor($r);
    }

    /** GET /api/tenant-webhooks */
    public function index(Request $request)
    {
        $this->requireAdmin($request);
        $s = $this->sess($request);
        return response()->json(TenantWebhook::where('domain', $s['domain'])->where('user', $s['user'])
            ->orderByDesc('id')->get()->values());
    }

    /** POST /api/tenant-webhooks — secret is generated + returned ONCE. */
    public function store(Request $request)
    {
        $this->requireAdmin($request);
        $s = $this->sess($request);
        $data = $request->validate([
            'url' => 'required|url|max:500',
            'events' => 'sometimes|array',
            'events.*' => Rule::in(TenantWebhook::EVENTS),
        ]);
        $plain = Str::random(32);
        $hook = TenantWebhook::create(['domain' => $s['domain'], 'user' => $s['user'],
            'url' => $data['url'], 'secret' => $plain,
            'events' => array_values($data['events'] ?? []), 'status' => 'active']);
        $this->audit($request, 'tenant-webhook.created', ['url' => $hook->url]);
        return response()->json(array_merge($hook->toArray(), ['secret' => $plain]), 201);
    }

    /** PUT /api/tenant-webhooks/{id} */
    public function update(Request $request, TenantWebhook $webhook)
    {
        $this->requireAdmin($request);
        $s = $this->sess($request);
        if ($webhook->domain !== $s['domain'] || $webhook->user !== $s['user']) abort(404);
        $data = $request->validate([
            'url' => 'sometimes|url|max:500',
            'events' => 'sometimes|array',
            'events.*' => Rule::in(TenantWebhook::EVENTS),
            'status' => 'sometimes|in:active,disabled',
        ]);
        if (array_key_exists('events', $data)) $data['events'] = array_values($data['events']);
        $webhook->update($data);
        $this->audit($request, 'tenant-webhook.updated', ['url' => $webhook->url, 'keys' => array_keys($data)]);
        return response()->json($webhook->fresh());
    }

    /** DELETE /api/tenant-webhooks/{id} */
    public function destroy(Request $request, TenantWebhook $webhook)
    {
        $this->requireAdmin($request);
        $s = $this->sess($request);
        if ($webhook->domain !== $s['domain'] || $webhook->user !== $s['user']) abort(404);
        $this->audit($request, 'tenant-webhook.deleted', ['url' => $webhook->url]);
        $webhook->delete();
        return response()->json(['ok' => true]);
    }

    /** POST /api/tenant-webhooks/{id}/test — queue a test ping. */
    public function test(Request $request, TenantWebhook $webhook)
    {
        $this->requireAdmin($request);
        $s = $this->sess($request);
        if ($webhook->domain !== $s['domain'] || $webhook->user !== $s['user']) abort(404);
        DeliverTenantWebhook::dispatch($webhook->id, 'webhook.test', ['ping' => true], true);
        $this->audit($request, 'tenant-webhook.tested', ['url' => $webhook->url]);
        return response()->json(['ok' => true]);
    }

    /** GET /api/tenant-webhooks/{id}/deliveries */
    public function deliveries(Request $request, TenantWebhook $webhook)
    {
        $this->requireAdmin($request);
        $s = $this->sess($request);
        if ($webhook->domain !== $s['domain'] || $webhook->user !== $s['user']) abort(404);
        return response()->json(WebhookDelivery::where('tenant_webhook_id', $webhook->id)
            ->orderByDesc('id')->limit(20)->get()->values());
    }
}

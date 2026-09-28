<?php

namespace App\Models;

use App\Jobs\DeliverTenantWebhook;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant outbound webhook: signed event callbacks (message.received,
 * message.sent, optout.added/removed) POSTed to the tenant's own URL.
 */
class TenantWebhook extends Model
{
    public const EVENTS = ['message.received', 'message.sent', 'optout.added', 'optout.removed'];

    protected $fillable = [
        'domain', 'user', 'url', 'secret', 'events',
        'status', 'failure_count', 'last_error', 'last_delivery_at',
    ];

    protected $hidden = ['secret'];

    protected $casts = [
        'secret' => 'encrypted',
        'events' => 'array',
        'last_delivery_at' => 'datetime',
    ];

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function wants(string $event): bool
    {
        $ev = $this->events ?? [];
        return $ev === [] || in_array($event, $ev, true);
    }

    /** Fan out one event to every active matching webhook (queued delivery). */
    public static function fire(string $domain, ?string $user, string $event, array $data): void
    {
        try {
            $q = static::where('domain', $domain)->where('status', 'active');
            if ($user !== null && $user !== '') $q->where('user', $user);
            foreach ($q->get() as $hook) {
                if (!$hook->wants($event)) continue;
                DeliverTenantWebhook::dispatch($hook->id, $event, $data);
            }
        } catch (\Throwable $e) {
            // Webhooks must never break the send/receive path.
        }
    }
}

<?php

namespace App\Events;

use App\Broadcasting\ChannelName;
use App\Services\BroadcastScope;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Cross-instance sync: every local mutation broadcasts here so other
 * browsers/computers refresh instantly (no polling, no manual refresh).
 *
 * Channel: private-sms.{sanitized domain}.{user} (see ChannelName)
 * Event name: data.changed
 * Payload: { resource, action, id, payload }
 */
class DataChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $domain,
        public string $user,
        public string $resource,  // convo-meta|agents|contacts|groups|templates|scheduled|auto-replies|sessions
        public string $action,    // saved|deleted|read|message-sent
        public mixed $id = null,
        public array $payload = [],
    ) {}

    /** One-liner for controllers/jobs. Never throws — sync must not break saves. */
    public static function send(string $domain, string $user, string $resource, string $action, mixed $id = null, array $payload = []): void
    {
        try {
            // Callers pass the ACTOR's scope; the channel must be the
            // domain's SHARED scope, or other participants (admin vs agents)
            // never hear each other. scopeFor collapses extension users to
            // the tenant's Dynalink user on tenant-managed domains and is a
            // no-op elsewhere.
            [$domain, $user] = BroadcastScope::scopeFor($domain, $user);
            broadcast(new self($domain, $user, $resource, $action, $id, $payload));
        } catch (\Throwable $e) {
            Log::warning('Sync broadcast failed: ' . $e->getMessage());
        }
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ChannelName::account($this->domain, $this->user))];
    }

    public function broadcastAs(): string
    {
        return 'data.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'resource' => $this->resource,
            'action'   => $this->action,
            'id'       => $this->id,
            'payload'  => $this->payload,
        ];
    }
}

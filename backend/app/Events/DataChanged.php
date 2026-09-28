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
 * Channel: the shared domain room private-sms.{sanitized domain}.shared
 * (BroadcastScope::scopeFor — the Dynalink user/extension is never part of
 * the channel, so agents on different extensions all hear every mutation).
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
            // The channel is the domain's SHARED room, never the actor's own
            // user extension — participants on different extensions (admin
            // vs agents) must all hear every mutation.
            [$domain, $room] = BroadcastScope::scopeFor($domain);
            broadcast(new self($domain, $room, $resource, $action, $id, $payload));
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // Turn the two most common credential failures into a fix recipe:
            // a key Reverb doesn't know (DB override from a migrated database,
            // stale .env) or a base64 key whose '+' characters the Pusher HTTP
            // API query string mangles into spaces.
            if (str_contains($msg, 'auth_key') || str_contains($msg, 'auth_signature') || str_contains($msg, 'Unknown app')) {
                $msg .= ' — Fix: the broadcaster key must equal REVERB_APP_KEY in the RUNNING Reverb process\'s .env, and must be letters/digits only (base64 keys with "+" or "=" break server publishing while browsers still connect). Check Super → Settings → Realtime (DB overrides beat .env — clear them after migrating a database), then restart all PHP processes.';
            }
            Log::warning('Sync broadcast failed: ' . $msg);
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

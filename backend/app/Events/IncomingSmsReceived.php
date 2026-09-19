<?php

namespace App\Events;

use App\Broadcasting\ChannelName;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast instantly (ShouldBroadcastNow — no queue delay) so the
 * React UI updates the moment Dynalink POSTs the webhook.
 *
 * Channel: private-sms.{sanitized domain}.{user} (see ChannelName)
 * Event name: sms.incoming
 */
class IncomingSmsReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $domain,
        public string $user,
        public array $event,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ChannelName::account($this->domain, $this->user))];
    }

    public function broadcastAs(): string
    {
        return 'sms.incoming';
    }

    public function broadcastWith(): array
    {
        $e = $this->event;
        return [
            'id'               => $e['id'] ?? null,
            'timestamp'        => $e['timestamp'] ?? now()->toDateTimeString(),
            'type'             => $e['type'] ?? 'sms',          // sms | mms
            'direction'        => $e['direction'] ?? 'orig',    // orig = inbound
            'dialed'           => $e['dialed'] ?? null,
            'text'             => $e['text'] ?? ($e['message'] ?? ''),
            'from-number'      => $e['from-number'] ?? null,
            'terminating-user-id' => $e['terminating-user-id'] ?? null,
            'messagesession-id' => $e['messagesession-id'] ?? $e['messagesession_id'] ?? null,
            'media-type'       => $e['media-type'] ?? '',
            'media-size'       => $e['media-size'] ?? 0,
            'file-access-url'  => $e['file-access-url'] ?? null,
            'raw'              => $e,
        ];
    }
}

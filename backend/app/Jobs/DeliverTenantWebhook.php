<?php

namespace App\Jobs;

use App\Models\TenantWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/** POST one signed event to a tenant webhook URL (retried, then recorded). */
class DeliverTenantWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function __construct(
        public int $hookId,
        public string $event,
        public array $data,
        public bool $test = false,
    ) {}

    public function handle(): void
    {
        $hook = TenantWebhook::find($this->hookId);
        if (!$hook) return;
        if ($hook->status !== 'active' && !$this->test) return;
        $body = json_encode([
            'event' => $this->event, 'at' => now()->toISOString(),
            'domain' => $hook->domain, 'data' => $this->data,
        ]);
        $sig = 'sha256=' . hash_hmac('sha256', $body, (string) $hook->secret);
        $code = null;
        try {
            $code = Http::timeout(10)
                ->withHeaders(['X-Webhook-Event' => $this->event, 'X-Webhook-Signature' => $sig])
                ->withBody($body, 'application/json')->post($hook->url)->status();
        } catch (\Throwable $e) {
            $code = null;
        }
        if ($code !== null && $code >= 200 && $code < 300) {
            $hook->update(['failure_count' => 0, 'last_delivery_at' => now(), 'last_error' => null]);
            WebhookDelivery::create(['tenant_webhook_id' => $hook->id, 'event' => $this->event,
                'payload' => $this->data, 'status_code' => $code, 'attempt' => $this->attempts()]);
            $this->prune($hook->id);
            return;
        }
        throw new \RuntimeException($code === null ? 'connection failed' : "HTTP {$code}");
    }

    public function failed(\Throwable $e): void
    {
        $hook = TenantWebhook::find($this->hookId);
        if (!$hook) return;
        $fc = (int) $hook->failure_count + 1;
        $hook->update(['failure_count' => $fc, 'last_error' => mb_substr($e->getMessage(), 0, 500),
            'status' => (!$this->test && $fc >= 20) ? 'disabled' : $hook->status]);
        WebhookDelivery::create(['tenant_webhook_id' => $hook->id, 'event' => $this->event,
            'payload' => $this->data, 'error' => mb_substr($e->getMessage(), 0, 500), 'attempt' => $this->tries]);
        $this->prune($hook->id);
    }

    protected function prune(int $hookId): void
    {
        try {
            $keep = WebhookDelivery::where('tenant_webhook_id', $hookId)->orderByDesc('id')->limit(50)->pluck('id');
            WebhookDelivery::where('tenant_webhook_id', $hookId)->whereNotIn('id', $keep)->delete();
        } catch (\Throwable $e) {}
    }
}

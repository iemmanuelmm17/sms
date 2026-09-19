<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Models\PushSubscription;
use App\Models\Tenant;
use App\Models\TenantAdmin;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Minishlink\WebPush\Notification;
use Minishlink\WebPush\WebPush;

/** Background Web Push alert for one inbound SMS (agents on that number + tenant admins). */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $domain,
        public string $user,
        public string $dialed,
        public string $from,
        public string $text,
        public ?string $sessionId,
    ) {}

    public function handle(): void
    {
        if (!class_exists(WebPush::class)) return;
        $pub = (string) config('services.webpush.public_key', '');
        $priv = (string) config('services.webpush.private_key', '');
        if ($pub === '' || $priv === '') return;
        try {
            $agentIds = Agent::where('domain', $this->domain)->where('user', $this->user)
                ->where('status', 'active')->get()
                ->filter(fn($a) => $this->numMatch($a))->map->id->all();
            $tenant = Tenant::where('domain', $this->domain)->where('dynalink_user', $this->user)->first();
            $adminIds = $tenant
                ? TenantAdmin::where('tenant_id', $tenant->id)->where('status', 'active')->pluck('id')->all()
                : [];
            if ($agentIds === [] && $adminIds === []) return;
            $subs = PushSubscription::where('domain', $this->domain)->where('user', $this->user)
                ->where(fn($q) => $q->whereIn('agent_id', $agentIds)->orWhereIn('tenant_admin_id', $adminIds))
                ->get()->unique('endpoint_hash');
            if ($subs->isEmpty()) return;
            $webPush = new WebPush(['VAPID' => [
                'subject' => (string) config('services.webpush.subject', 'mailto:admin@localhost'),
                'publicKey' => $pub, 'privateKey' => $priv,
            ]]);
            $payload = json_encode(['title' => 'New SMS from ' . $this->from,
                'body' => $this->text !== '' ? $this->text : '[media message]',
                'url' => '/app/messages', 'tag' => $this->sessionId ?? 'sms']);
            foreach ($subs as $s) {
                $webPush->queueNotification(new Notification($s->toWebPushArray(), $payload));
            }
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) continue;
                $code = null;
                try { $code = $report->getResponse()?->getStatusCode(); } catch (\Throwable $e) {}
                if ($code === 404 || $code === 410) {
                    PushSubscription::where('endpoint_hash', hash('sha256', (string) $report->getEndpoint()))->delete();
                }
            }
        } catch (\Throwable $e) {
            // Push must never break inbound processing.
        }
    }

    protected function numMatch($a): bool
    {
        if ($this->dialed === '') return true; // unknown number → fail open
        $d = substr(preg_replace('/\D/', '', $this->dialed), -10);
        if ($d === '') return true;
        $cands = array_merge([$a->default_number], (array) ($a->allowed_numbers ?? []));
        foreach ($cands as $c) {
            $x = substr(preg_replace('/\D/', '', (string) $c), -10);
            if ($x !== '' && $x === $d) return true;
        }
        return false;
    }
}

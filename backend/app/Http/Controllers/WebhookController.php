<?php

namespace App\Http\Controllers;

use App\Events\IncomingSmsReceived;
use App\Http\Middleware\EnsureSuperAdminIp;
use App\Models\AuditLog;
use App\Models\WebhookAllowedIp;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Receives Dynalink `message` / `messagesession` subscription POSTs
 * and instantly pushes them to the React UI over Reverb WebSockets.
 *
 * No polling anywhere: webhook → broadcast → UI updates.
 */
class WebhookController extends Controller
{
    /**
     * POST /api/webhooks/dynalink
     * (Excluded from CSRF + auth — called server-to-server by Dynalink.)
     */
    public function dynalink(Request $request)
    {
        // Source auth: Dynalink IPs only (superadmin-managed allowlist).
        // Fail closed — unknown source, no DB write, no broadcast.
        // NOTE: behind a tunnel/proxy, trusted proxies must be configured
        // or $request->ip() is the proxy's IP and everything 403s.
        $srcIp = (string) $request->ip();
        try {
            $allowed = Cache::remember('webhook:ips', 300,
                fn() => WebhookAllowedIp::query()->pluck('cidr')->all());
        } catch (\Throwable $e) {
            $allowed = [];
        }
        $srcOk = false;
        foreach ($allowed as $cidr) {
            if (EnsureSuperAdminIp::matches($srcIp, (string) $cidr)) { $srcOk = true; break; }
        }
        if (!$srcOk) {
            Log::warning('Dynalink webhook denied (IP not allowed)', ['ip' => $srcIp]);
            if (Cache::add("webhook:denied:{$srcIp}", 1, now()->addHour())) {
                AuditLog::record(null, 'console', null, $srcIp,
                    'webhook.denied', ['reason' => 'ip-not-allowed'], $srcIp);
            }
            abort(403, 'Forbidden.');
        }

        // Correlation ID: always captured; optionally required (superadmin toggle).
        // Dynalink sends a random ID per request — presence is what's verified.
        $correlationId = trim((string) ($request->header('X-Correlation-ID')
            ?? $request->header('X-Request-ID') ?? ''));
        if ($correlationId === '' && Settings::get('webhook.require_correlation_id', '0') === '1') {
            Log::warning('Dynalink webhook denied (missing correlation ID)', ['ip' => $srcIp]);
            if (Cache::add("webhook:denied:{$srcIp}", 1, now()->addHour())) {
                AuditLog::record(null, 'console', null, $srcIp,
                    'webhook.denied', ['reason' => 'correlation-missing'], $srcIp);
            }
            abort(403, 'Forbidden.');
        }

        $payload = $request->all();

        // Normalize: single event object or list of events.
        $events = isset($payload[0]) && is_array($payload[0]) ? $payload : [$payload];
        // Metadata only — never log message text or sender numbers (customer PII).
        Log::info('Dynalink webhook', ['events' => count($events), 'summary' => array_map(fn($e) => [
            'domain' => $e['domain'] ?? null,
            'user' => $e['user'] ?? $e['terminating-user-id'] ?? null,
            'session' => $e['messagesession-id'] ?? $e['session_id'] ?? null,
            'has_text' => trim((string) ($e['text'] ?? $e['last_mesg'] ?? '')) !== '',
        ], $events)]);

        // Persist for the Auto-reply "recent inbound events" diagnostics viewer.
        try {
            foreach ($events as $e) {
                \App\Models\WebhookEvent::create([
                    'domain' => $e['domain'] ?? null,
                    'user'   => isset($e['user']) ? (string) $e['user']
                        : (isset($e['terminating-user-id']) ? (string) $e['terminating-user-id'] : null),
                    'correlation_id' => $correlationId !== '' ? $correlationId : null,
                    'event'  => $e,
                ]);
            }
            $keep = \App\Models\WebhookEvent::orderByDesc('id')->limit(100)->pluck('id');
            \App\Models\WebhookEvent::whereNotIn('id', $keep)->delete();
        } catch (\Throwable $e) {
            Log::warning('WebhookEvent store failed: ' . $e->getMessage());
        }

        foreach ($events as $event) {
            // Direction filter: only inbound (orig) pushes "incoming SMS".
            // Session-update events are pushed too so read-marks refresh.
            $user   = $event['terminating-user-id'] ?? $event['user'] ?? null;
            $domain = $event['domain'] ?? null;

            // Channel is scoped per user: sms.{domain}.{user}
            // e.g. sms.1180.DynaCloud.6001
            $channelUser = $user;
            if (is_string($channelUser) && str_contains($channelUser, '@')) {
                [$u, $d] = explode('@', $channelUser, 2);
                $channelUser = $u;
                $domain = $domain ?: $d;
            }

            // Canonical shape: session events (remote/last_mesg/session_id)
            // are mapped onto message keys so everything downstream speaks
            // one dialect. Storage above keeps the raw event.
            $ev = $this->normalizeEvent($event);

            // Only real inbound messages push "incoming SMS". Textless
            // receipts and outbound echoes would paint phantom threads;
            // already-read session refires would duplicate bubbles.
            $dir = $ev['direction'] ?? 'orig';
            $hasText = trim((string) ($ev['text'] ?? '')) !== '';
            $hasFile = ! empty($ev['file-access-url'] ?? null);
            $isNew = ($event['last_status'] ?? 'unread') === 'unread';
            $isMessage = $dir === 'orig' && ($hasText || $hasFile) && $isNew;

            if ($isMessage) {
                // Twins (message + session event per SMS) collapse to one
                // broadcast — whichever arrives first wins.
                $seen = 'bcast-sent:' . md5(($ev['messagesession-id'] ?? '') . '|' . ($ev['from-number'] ?? '') . '|' . ($ev['text'] ?? ''));
                if (Cache::add($seen, 1, now()->addSeconds(30))) {
                    if ($channelUser && $domain) {
                        broadcast(new IncomingSmsReceived(
                            (string) $domain,
                            (string) $channelUser,
                            $ev
                        ))->toOthers();
                    } else {
                        // Fallback: broadcast on a global channel the client can also join.
                        broadcast(new IncomingSmsReceived('global', 'all', $ev))->toOthers();
                    }
                    // Tenant outbound webhooks + background push (twins fire once).
                    if (!empty($domain) && !empty($channelUser)) {
                        \App\Models\TenantWebhook::fire((string) $domain, (string) $channelUser, 'message.received', [
                            'session_id' => $ev['messagesession-id'] ?? null,
                            'from' => $ev['from-number'] ?? null,
                            'dialed' => $ev['dialed'] ?? null,
                            'text' => $ev['text'] ?? null,
                            'type' => !empty($ev['file-access-url'] ?? null) ? 'mms' : 'sms',
                            'at' => $ev['timestamp'] ?? now()->toISOString(),
                        ]);
                        \App\Jobs\SendPushNotification::dispatch((string) $domain, (string) $channelUser,
                            (string) ($ev['dialed'] ?? ''), (string) ($ev['from-number'] ?? ''),
                            mb_substr((string) ($ev['text'] ?? ''), 0, 160),
                            isset($ev['messagesession-id']) ? (string) $ev['messagesession-id'] : null);
                    }
                }
                // Email gateway: SMS-received notification (never throws).
                // Outside the twin-guard on purpose: the service claims its
                // own key only when the number is known, so a numberless
                // message-first twin can't suppress the session twin's mail.
                try {
                    app(\App\Services\EmailSmsService::class)->notifySmsReceived(
                        (string) ($domain ?? ''), (string) ($channelUser ?? ''), $ev);
                } catch (\Throwable $e) {
                    Log::warning('EmailSms notify failed: ' . $e->getMessage());
                }
            }

            // Keyword auto-reply (inbound messages only; never throws).
            // (STOP/START opt-out keywords are handled inside maybeReply.)
            try {
                app(\App\Services\AutoReplyService::class)->maybeReply($ev);
            } catch (\Throwable $e) {
                Log::warning('AutoReply failed: ' . $e->getMessage());
            }
            // Inbound changed the session list → drop the cached list.
            if (!empty($domain) && !empty($channelUser)) {
                try { \App\Services\DynalinkService::forgetSessions((string) $domain, (string) $channelUser); } catch (\Throwable $e) {}
            }
        }

        return response()->json(['ok' => true, 'correlation_id' => $correlationId !== '' ? $correlationId : null]);
    }

    /**
     * Map a session-shaped event onto message keys (never overwriting real
     * keys): remote -> from-number, last_mesg -> text, session_id ->
     * messagesession-id, smsani -> dialed, last_sender -> direction.
     * Also synthesizes a stable id + ISO timestamp for events that lack them
     * so the UI's duplicate check and ordering keep working.
     */
    protected function normalizeEvent(array $event): array
    {
        $event['from-number'] ??= $event['remote'] ?? $event['last_sender'] ?? null;
        $event['text'] ??= $event['last_mesg'] ?? null;
        $event['messagesession-id'] ??= $event['session_id'] ?? null;
        $event['dialed'] ??= $event['smsani'] ?? null;
        if (!isset($event['direction']) && !isset($event['dir'])) {
            $ls = (string) ($event['last_sender'] ?? '');
            $rm = (string) ($event['remote'] ?? '');
            if ($ls !== '' && $rm !== '') {
                $event['direction'] = $ls === $rm ? 'orig' : 'term';
            }
        }
        if (isset($event['text']) && !is_string($event['text'])) {
            $event['text'] = is_numeric($event['text']) ? (string) $event['text'] : null;
        }
        if (!isset($event['id'])) {
            $event['id'] = 'evt-' . md5(($event['session_id'] ?? '') . '|' . ($event['last_mesg'] ?? '') . '|' . ($event['last_timestamp'] ?? ''));
        }
        if (!isset($event['timestamp']) && !empty($event['last_timestamp'])) {
            $event['timestamp'] = date('c', strtotime($event['last_timestamp']));
        }
        return $event;
    }
}

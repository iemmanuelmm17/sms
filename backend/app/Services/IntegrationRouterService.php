<?php

namespace App\Services;

use App\Events\DataChanged;
use App\Models\Integration;
use App\Models\IntegrationNumber;
use App\Models\SentMessageLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Routes inbound texts to the integration assigned to the number they
 * were sent to. True = an integration owned the message (caller must
 * skip normal auto-reply rules). Sends mirror a manual in-session
 * reply (type sms) and count in Reporting as auto-replies.
 */
class IntegrationRouterService
{
    public function __construct(
        protected DynalinkService $dynalink,
        protected OptOutService $optouts,
        protected RevioDialogService $revioDialog,
    ) {}

    public function maybeHandle(array $event, string $domain, string $user, string $from, string $text): bool
    {
        try {
            $ours = preg_replace('/\D/', '', (string) ($event['dialed'] ?? $event['smsani'] ?? ''));
            if ($ours === '') return false;
            $assign = IntegrationNumber::where('domain', $domain)->where('user', $user)
                ->where('number', $ours)->first();
            if (!$assign || !$assign->integration || !$assign->integration->password) return false;
            $integration = $assign->integration;
            if ($integration->provider !== Integration::PROVIDER_REVIO) return false;

            $sender = (string) ($event['smsani'] ?? $event['dialed'] ?? '');
            // TCPA: never answer a number opted out of this sender. The
            // message is still owned (rules stay skipped) — just silent.
            if ($this->optouts->isOptedOut($domain, $from, $sender !== '' ? $sender : null)) {
                Log::info('Integration: blocked (opted out)',
                    ['from_hash' => substr(hash('sha256', (string) $from), 0, 12)]);
                return true;
            }

            $token = app(AutoReplyService::class)->userToken($domain, $user);
            if (!$token) {
                Log::warning("Integration: no stored token for {$user}@{$domain}");
                return false;
            }
            $ctx = ['token' => $token, 'domain' => $domain, 'user' => $user, 'sender' => $sender];

            try {
                $reply = $this->revioDialog->handle($integration, $from, $text, $ctx);
            } catch (\Throwable $e) {
                Log::warning('Integration dialog failed: ' . $e->getMessage());
                $reply = IntegrationSpiels::get($integration, 'system_error');
            }
            if ($reply === null || trim($reply) === '') return true;

            $sessionId = (string) ($event['messagesession-id'] ?? $event['messagesession_id'] ?? $event['session_id'] ?? '');
            $payload = [
                'type'        => 'sms',
                'message'     => $reply,
                'from-number' => $sender,
            ];
            if ($sender !== '' && $sender !== $from) {
                $payload['destination'] = $from;
            }
            if ($sessionId !== '') {
                [$status, $body] = $this->dynalink->sendInSession($token, $domain, $user, $sessionId, $payload);
            } else {
                [$status, $body] = $this->dynalink->sendNew($token, $domain, $user, $payload);
            }
            if ($status < 200 || $status >= 300) {
                // Twin event still gets its chance — mirrors auto-reply.
                Log::warning("Integration reply send failed: HTTP {$status}");
                return false;
            }

            SentMessageLog::record([
                'tenant_id' => SentMessageLog::tenantFor($domain, $user),
                'domain' => $domain, 'user' => $user,
                'agent_id' => null,
                'actor_name' => 'Integration (' . Integration::labelFor($integration->provider) . ')',
                'category' => SentMessageLog::AUTO_REPLY,
                'auto_reply_id' => null,
                'session_id' => $sessionId !== '' ? $sessionId : null,
                'from_number' => $sender !== '' ? $sender : null,
                'to_number' => $from !== '' ? $from : null,
                'type' => 'sms',
            ]);
            Cache::put(AutoReplyService::dedupeKey($domain, $user, $from, $text), 1, now()->addMinutes(5));
            DataChanged::send($domain, $user, 'sessions', 'message-sent', null, ['remote' => $from]);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Integration router failed: ' . $e->getMessage());
            return false;
        }
    }
}

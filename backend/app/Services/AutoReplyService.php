<?php

namespace App\Services;

use App\Models\AutoReply;
use App\Models\AutoReplyLog;
use App\Events\DataChanged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\SentMessageLog;

/**
 * Keyword auto-reply engine. The Dynalink webhook calls maybeReply()
 * for every event; only inbound (orig) messages with text are evaluated.
 * Matching is case-insensitive substring search.
 */
class AutoReplyService
{
    public function __construct(protected DynalinkService $dynalink, protected OptOutService $optouts) {}

    /**
     * Find rules matching $text.
     * match_mode "any": fires when ANY keyword appears.
     * match_mode "all": fires only when EVERY keyword appears.
     * Returns [['rule' => AutoReply, 'keyword' => string], ...]
     */
    public function findMatches(Collection $rules, string $text): array
    {
        // Punctuation-insensitive: "what time?" matches "what time".
        // Applied symmetrically so old matches keep matching.
        $norm = function ($s) {
            $s = mb_strtolower((string) $s);
            $s = preg_replace('/[^a-z0-9 ]/', ' ', $s);
            return trim((string) preg_replace('/ +/', ' ', (string) $s));
        };
        $hay = $norm($text);
        $out = [];
        foreach ($rules as $rule) {
            // Catch-all: answers ANY inbound message, no keywords involved.
            if ($rule->isCatchAll()) {
                $out[] = ['rule' => $rule, 'keyword' => '(any message)'];
                continue;
            }
            $keywords = array_values(array_filter(array_map(
                fn($k) => $norm(trim((string) $k)),
                (array) ($rule->keywords ?? [])
            ), fn($k) => $k !== ''));
            if (empty($keywords)) continue;

            if ($rule->match_mode === 'exact') {
                foreach ($keywords as $k) {
                    if ($hay === $k) { $out[] = ['rule' => $rule, 'keyword' => $k]; break; }
                }
                continue;
            }
            if ($rule->match_mode === 'all') {
                $allHit = true;
                foreach ($keywords as $k) {
                    if (!str_contains($hay, $k)) { $allHit = false; break; }
                }
                if ($allHit) $out[] = ['rule' => $rule, 'keyword' => implode(', ', $keywords)];
            } else {
                foreach ($keywords as $k) {
                    if (str_contains($hay, $k)) { $out[] = ['rule' => $rule, 'keyword' => $k]; break; }
                }
            }
        }
        return $out;
    }

    /** Evaluate one webhook event and auto-reply on match. Never throws. */
    public static function dedupeKey(string $domain, string $user, string $from, string $text): string
    {
        return "autoreply:fired:{$domain}:{$user}:" . md5($from . '|' . $text);
    }

    public function maybeReply(array $event): void
    {
        try {
            Log::info('AutoReply: webhook received', ['keys' => array_keys($event)]);

            [$domain, $user] = $this->resolveUser($event);
            if (!$domain || !$user) return;
            // Accept field-name variants — Dynalink delivers TWO event shapes
            // per SMS. Message events carry direction/text/from-number; session
            // events carry last_mesg/remote/session_id/smsani instead.
            $from = preg_replace('/\\D/', '', (string) (
                $event['from-number'] ?? $event['from_number'] ?? $event['from'] ?? $event['caller'] ?? $event['remote'] ?? $event['last_sender'] ?? ''
            ));
            $rawText = $event['text'] ?? $event['message'] ?? $event['body'] ?? $event['last_mesg'] ?? '';
            $text = is_string($rawText) ? trim($rawText) : (is_numeric($rawText) ? (string) $rawText : '');
            if ($text === '' || $from === '') return;

            // Inbound only. Message events say so via direction; session events
            // (no direction field) via last_sender — our own reply arrives with
            // last_sender == smsani and must never re-trigger (self-loop).
            $direction = strtolower((string) ($event['direction'] ?? $event['dir'] ?? ''));
            if ($direction !== '') {
                if ($direction !== 'orig') return;
            } elseif (($ls = (string) ($event['last_sender'] ?? '')) !== '' && preg_replace('/\\D/', '', $ls) !== $from) {
                return;
            }

            // Type matters: only SMS legs trigger an SMS auto-reply.
            if (isset($event['last_mesg_type']) && strtolower((string) $event['last_mesg_type']) !== 'sms') return;

            // Both subscriptions fire per SMS (message + session event) — after
            // a successful reply, ignore the twin. Failures don't consume the
            // dedupe so the twin event still gets its chance to deliver.
            $dedupeKey = self::dedupeKey($domain, $user, $from, $text);
            // Atomic twin-claim: both subscriptions fire per SMS, so exactly
            // one handler must win. The claim doubles as the 5-minute
            // duplicate guard (same TTL the fired-key always had).
            try {
                if (!Cache::lock($dedupeKey . ':claim', 300)->get()) return;
            } catch (\Throwable $e) {
                if (Cache::has($dedupeKey)) return; // lock driver unavailable
            }
            // Per-sender cooldown (tenant setting, default 5 min): at most one
            // auto-reply per window no matter how the text varies. Gates the
            // REPLY only — STOP/START bookkeeping below always runs.
            $cooldownMin = app(\App\Services\CompanySettingsService::class)->cooldown($domain);
            $cdKey = "autoreply:cooldown:{$domain}:{$user}:{$from}";
            $inCooldown = $cooldownMin > 0 && Cache::has($cdKey);
            Log::info('AutoReply: guards passed', ['from_hash' => substr(hash('sha256', (string) $from), 0, 12), 'text_len' => strlen($text)]);

            // TCPA STOP/START: exact keyword match does the bookkeeping here
            // (opt-out/in + history event). The reply itself comes from the two
            // DEFAULT auto-reply actions below — custom rules never answer STOP.
            $stopWord = OptOutService::stopWord($text);
            $startWord = $stopWord ? null : OptOutService::startWord($text);
            $optKeyword = $stopWord ?? $startWord;
            $stateChanged = false;
            if ($optKeyword) {
                if ($stopWord) {
                    // Scope the opt-out to the business number they replied to.
                    $stopNumber = (string) ($event['dialed'] ?? $event['smsani'] ?? '');
                    $stateChanged = $this->optouts->optOut($domain, $from, 'stop-keyword', $stopWord, $stopNumber !== '' ? $stopNumber : null);
                } else {
                    $stateChanged = $this->optouts->remove($domain, $from, $startWord);
                }
                DataChanged::send($domain, $user, 'optouts', 'saved');
                DataChanged::send($domain, $user, 'opt-events', 'saved');
                Log::info('AutoReply: STOP/START processed', ['from_hash' => substr(hash('sha256', (string) $from), 0, 12), 'word' => $optKeyword]);
            }


            // Integration-owned numbers: the provider dialog answers instead
            // of these rules. STOP/START texts (above) always stay on this
            // path so the default compliance confirmations still go out.
            if (!$optKeyword && app(\App\Services\IntegrationRouterService::class)->maybeHandle($event, $domain, $user, $from, $text)) {
                return;
            }
            $rules = AutoReply::where('domain', $domain)->where('user', $user)
                ->where('active', true)->orderBy('priority')->orderBy('id')->get();
            Log::info('AutoReply: rules loaded', ['count' => $rules->count()]);
            if ($rules->isEmpty()) return;

            $matches = $this->findMatches($rules, $text);
            if ($optKeyword) {
                // STOP/START is answered ONLY by the default actions.
                $matches = array_values(array_filter($matches, fn($m) => (bool) $m['rule']->is_default));
            }
            // Line scope: defaults fire everywhere (compliance); admin rules
            // fire on the tenant main line only; agent rules fire on their
            // creator's assigned numbers except the main line.
            $inboundDigits = preg_replace('/\D/', '', (string) ($event['dialed'] ?? $event['smsani'] ?? ''));
            $mainDigits = '';
            try { $mainDigits = preg_replace('/\D/', '', (string) (\App\Models\Tenant::where('domain', $domain)->where('dynalink_user', $user)->value('main_number') ?? '')); } catch (\Throwable $e) {}
            $agentNumsCache = [];
            $matches = array_values(array_filter($matches, function ($m) use ($inboundDigits, $mainDigits, &$agentNumsCache) {
                $rule = $m['rule'];
                // Compliance actions (STOP/START) always answer, whatever the scope.
                if ($rule->is_default) return true;

                // Per-rule active window (days + local time range).
                // Catch-all rules are 24/7 by design — a window never applies.
                if (!$rule->isCatchAll() && !$rule->inSchedule()) {
                    Log::info('AutoReply: skipped (outside active hours)', ['rule' => $rule->id]);
                    return false;
                }

                // Per-rule numbers: null = pre-feature rule → old behaviour.
                $scope = $rule->scopeNumbers();
                if ($scope === null) return $this->legacyLineOk($rule, $inboundDigits, $mainDigits, $agentNumsCache);
                if (in_array(AutoReply::ALL_NUMBERS, $scope, true)) return true;
                if ($inboundDigits === '') return true; // event has no number → don't block
                return in_array($inboundDigits, $scope, true);
            }));
            if (empty($matches)) return;

            // Priority: the top-ranked eligible rule wins — exactly ONE reply
            // per inbound message. Everything below it stays silent for this
            // message (paused rules and rules outside their window are already
            // filtered out above, so the next eligible one simply moves up).
            $skipped = array_slice($matches, 1);
            $matches = [$matches[0]];
            Log::info('AutoReply: matches', [
                'count' => count($matches),
                'winner' => $matches[0]['rule']->id,
                'skipped' => array_map(fn($m) => $m['rule']->id, $skipped),
            ]);
            if (empty($matches)) return;

            // Sender cooldown: suppress the reply, but the FIRST opt-out/in
            // confirmation always goes out (compliance beats cooldown).
            if ($inCooldown && !($optKeyword && $stateChanged)) {
                Log::info('AutoReply: skipped (sender cooldown)', ['domain' => $domain, 'user' => $user]);
                return;
            }

            // TCPA: opt-out is checked per rule inside the send loop (sender-aware).

            $token = $this->userToken($domain, $user);
            if (!$token) {
                Log::warning("AutoReply: no stored token for {$user}@{$domain}");
                return;
            }

            // Reply inside the SAME messagesession the inbound arrived on —
            // Dynalink only delivers reliably in-session (sendNew strands the reply).
            $sessionId = (string) ($event['messagesession-id'] ?? $event['messagesession_id'] ?? $event['session_id'] ?? '');
            $anySent = false;
            foreach ($matches as $m) {
                /** @var AutoReply $rule */
                $rule = $m['rule'];
                try {
                    // Same call as a manual in-session reply: type sms,
                    // sender = rule override, else the event's SMS ANI.
                    $sender = $rule->from_number ?: (string) ($event['smsani'] ?? $event['dialed'] ?? '');
                    // TCPA: never auto-reply to a number opted out of this sender.
                    // Default compliance actions always fire (they ARE the opt-out reply).
                    if (!$rule->is_default && $this->optouts->isOptedOut($domain, $from, $sender !== '' ? $sender : null)) {
                        Log::info('AutoReply: blocked (opted out)', ['from_hash' => substr(hash('sha256', (string) $from), 0, 12), 'rule' => $rule->id]);
                        AutoReplyLog::create([
                            'auto_reply_id' => $rule->id, 'domain' => $domain, 'user' => $user,
                            'from_number' => $from, 'matched_keyword' => $m['keyword'],
                            'status' => 'blocked',
                            'detail' => 'Number opted out (do-not-contact).',
                        ]);
                        DataChanged::send($domain, $user, 'auto-replies', 'saved');
                        continue;
                    }
                    $replyText = app(\App\Services\CompanySettingsService::class)->resolve($domain, $rule->message);
                    $payload = [
                        'type'        => 'sms',
                        'message'     => $replyText,
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
                    $ok = $status >= 200 && $status < 300;
                    AutoReplyLog::create([
                        'auto_reply_id' => $rule->id, 'domain' => $domain, 'user' => $user,
                        'from_number' => $from, 'matched_keyword' => $m['keyword'],
                        'status' => $ok ? 'sent' : 'failed',
                        'detail' => $ok ? null : (is_string($body) ? $body : json_encode($body)),
                    ]);
                    if ($ok) {
                        $rule->increment('trigger_count');
                        $rule->update(['last_triggered_at' => now()]);
                        $anySent = true;
                        $toDigits = preg_replace('/\D/', '', (string) $from);
                        SentMessageLog::record([
                            'tenant_id' => SentMessageLog::tenantIdFor($domain, $user),
                            'domain' => $domain, 'user' => $user,
                            'agent_id' => null,
                            'actor_name' => 'Auto-reply',
                            'category' => SentMessageLog::AUTO_REPLY,
                            'auto_reply_id' => $rule->id,
                            'session_id' => $sessionId !== '' ? $sessionId : null,
                            'from_number' => $sender !== '' ? $sender : null,
                            'to_number' => $toDigits !== '' ? $toDigits : null,
                            'type' => 'sms',
                        ]);
                    }
                } catch (\Throwable $e) {
                    AutoReplyLog::create([
                        'auto_reply_id' => $rule->id, 'domain' => $domain, 'user' => $user,
                        'from_number' => $from, 'matched_keyword' => $m['keyword'],
                        'status' => 'failed', 'detail' => $e->getMessage(),
                    ]);
                }
            }
            // Live-sync other instances: new trigger log + updated session.
            // The session refresh only fires when a reply actually went out —
            // a failed send must not paint phantom updates.
            DataChanged::send($domain, $user, 'auto-replies', 'saved');
            if ($anySent) {
                Cache::put($dedupeKey, 1, now()->addMinutes(5));
                if ($cooldownMin > 0) Cache::put($cdKey, 1, now()->addMinutes($cooldownMin));
                DataChanged::send($domain, $user, 'sessions', 'message-sent', null, ['remote' => $from]);
            }
        } catch (\Throwable $e) {
            Log::warning('AutoReply failed: ' . $e->getMessage());
        }
    }

    /**
     * Line scope for rules created before number assignment existed:
     * agent rules fire on their creator's assigned numbers (never the main
     * line); admin rules fire on the tenant main line only.
     */
    protected function legacyLineOk(AutoReply $rule, string $inboundDigits, string $mainDigits, array &$agentNumsCache): bool
    {
        $cb = (string) ($rule->created_by ?? '');
        if (str_starts_with($cb, 'agent:')) {
            $aid = (int) substr($cb, 6);
            if (!array_key_exists($aid, $agentNumsCache)) {
                $nums = [];
                try { if ($ag = \App\Models\Agent::find($aid)) $nums = $ag->assignedNumbers(); } catch (\Throwable $e) {}
                $agentNumsCache[$aid] = array_map(fn($n) => preg_replace('/\D/', '', (string) $n), (array) $nums);
            }
            foreach ($agentNumsCache[$aid] as $n) {
                if ($n !== '' && $n === $inboundDigits && $n !== $mainDigits) return true;
            }
            return false;
        }
        if ($mainDigits === '' || $inboundDigits === '') return true;
        return $inboundDigits === $mainDigits;
    }

    /** [domain, user] from a webhook event. */

    protected function resolveUser(array $event): array
    {
        $term = $event['terminating-user-id'] ?? null;
        if (is_string($term) && str_contains($term, '@')) {
            [$u, $d] = explode('@', $term, 2);
            return [$d, $u];
        }
        return [$event['domain'] ?? null, isset($event['user']) ? (string) $event['user'] : null];
    }

    /**
     * Access token for webhook context (no session available).
     * Uses the refresh token stored at login, then rotates it.
     */
    public function userToken(string $domain, string $user): ?string
    {
        $key = "dynalink:rt:{$domain}:{$user}";
        $enc = Cache::get($key);
        if (!$enc) return null;
        try {
            $tokens = $this->dynalink->refreshToken(decrypt($enc));
        } catch (\Throwable $e) {
            return null;
        }
        if (!empty($tokens['refresh_token'])) {
            Cache::put($key, encrypt($tokens['refresh_token']), now()->addDays(30));
        }
        return $tokens['access_token'] ?? null;
    }
}

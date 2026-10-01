<?php

namespace App\Services;

use App\Events\DataChanged;
use App\Models\KeywordAlert;
use App\Models\KeywordAlertLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keyword watchlist: "notify the admin, never reply".
 *
 * Sibling of AutoReplyService — same tenant rule partition, same event
 * fields, same keyword matching (findMatches() is reused verbatim) — but a
 * match only writes a KeywordAlertLog row and broadcasts it, so every admin
 * window gets a live toast + nav badge + feed row.
 *
 * Watches BOTH directions: SMS received on a tenant number ('orig' events)
 * and SMS sent from one ('term' events). Dynalink delivers every message
 * twice (session twin + message twin, ~1s apart), so each rule claims a
 * short twin window: exactly one alert per message per rule.
 */
class KeywordAlertService
{
    /** Covers Dynalink's ~1s-apart twins without eating into re-test speed. */
    protected const TWIN_SEC = 20;

    /** Evaluate one normalized webhook event; log an alert per matching rule. Never throws. */
    public function maybeAlert(array $event): void
    {
        try {
            [$domain, $user] = AutoReplyService::resolveUser($event);
            if (!$domain || !$user) return;

            $rawText = $event['text'] ?? $event['message'] ?? $event['body'] ?? $event['last_mesg'] ?? '';
            $text = is_string($rawText) ? trim($rawText) : (is_numeric($rawText) ? (string) $rawText : '');
            if ($text === '') return;   // a keyword alert needs words to match

            // 'term' = sent from the tenant's line; everything else ('orig'
            // and the rare direction-less event) counts as received.
            $dirRaw = strtolower(trim((string) ($event['direction'] ?? $event['dir'] ?? '')));
            $direction = $dirRaw === 'term' ? KeywordAlert::DIR_OUT : KeywordAlert::DIR_IN;

            $d = fn($v) => preg_replace('/\D/', '', (string) $v);
            $smsani     = $d($event['smsani'] ?? '');
            $dialed     = $d($event['dialed'] ?? '');
            $fromNum    = $d($event['from_num'] ?? '');
            $remote     = $d($event['remote'] ?? '');
            $lastSender = $d($event['last_sender'] ?? '');
            // The same battle-tested counterparty chain AutoReplyService uses.
            $fromChain  = $d($event['from-number'] ?? $event['from_number'] ?? $event['from']
                ?? $event['caller'] ?? $event['from_num'] ?? $event['remote'] ?? $event['last_sender'] ?? '');

            if ($direction === KeywordAlert::DIR_IN) {
                $smsNumber    = $dialed !== '' ? $dialed : $smsani;
                $counterparty = ($fromChain !== '' && $fromChain !== $smsNumber) ? $fromChain : $remote;
                $fromNumber   = $counterparty;
                $toNumber     = $smsNumber;
            } else {
                $smsNumber    = $fromNum !== '' ? $fromNum : ($lastSender !== '' ? $lastSender : $smsani);
                $counterparty = $remote !== '' ? $remote
                    : (($fromChain !== '' && $fromChain !== $smsNumber) ? $fromChain : '');
                $fromNumber   = $smsNumber;
                $toNumber     = $counterparty;
            }

            $partition = AutoReplyService::rulePartitionUser($domain, $user);
            $rules = KeywordAlert::where('domain', $domain)->where('user', $partition)
                ->where('active', true)->get();
            if ($rules->isEmpty()) return;

            // Reuse the auto-reply matcher verbatim: KeywordAlert duck-types
            // AutoReply (keywords array, match_mode, isCatchAll()).
            $matches = app(AutoReplyService::class)->findMatches($rules, $text);
            if (empty($matches)) return;

            $sessionId = ((string) ($event['messagesession-id'] ?? $event['session_id'] ?? '')) ?: null;
            try {
                $occurredAt = Carbon::parse((string) ($event['timestamp'] ?? ''));
            } catch (\Throwable $e) {
                $occurredAt = now();
            }

            foreach ($matches as $m) {
                /** @var KeywordAlert $rule */
                $rule = $m['rule'];

                // Direction filter: in | out | both.
                if (!in_array((string) $rule->direction, [KeywordAlert::DIR_BOTH, $direction], true)) continue;

                // Number scope: empty = every tenant number. A scoped rule
                // needs a known tenant line — an unknown number never matches.
                $nums = array_values(array_filter(array_map(
                    fn($n) => preg_replace('/\D/', '', (string) $n),
                    (array) ($rule->numbers ?? [])
                ), fn($n) => $n !== ''));
                if (!empty($nums) && !in_array($smsNumber, $nums, true)) continue;

                // Twin de-dupe: one alert per message per rule.
                $twinKey = "keywordalert:twin:{$rule->id}:"
                    . md5($domain . '|' . $direction . '|' . $counterparty . '|' . $smsNumber . '|' . $text);
                if (!Cache::add($twinKey, 1, now()->addSeconds(self::TWIN_SEC))) continue;

                $log = KeywordAlertLog::create([
                    'keyword_alert_id'  => $rule->id,
                    'domain'            => $domain,
                    'user'              => $partition,
                    'rule_name'         => $rule->name,
                    'direction'         => $direction,
                    'matched_keyword'   => (string) ($m['keyword'] ?? ''),
                    'from_number'       => $fromNumber !== '' ? $fromNumber : null,
                    'to_number'         => $toNumber !== '' ? $toNumber : null,
                    'sms_number'        => $smsNumber !== '' ? $smsNumber : null,
                    'message_text'      => mb_substr($text, 0, 2000),
                    'messagesession_id' => $sessionId,
                    'occurred_at'       => $occurredAt,
                ]);
                $rule->increment('trigger_count');
                $rule->update(['last_triggered_at' => now()]);
                Log::info('KeywordAlert: triggered', [
                    'rule_id' => $rule->id, 'keyword' => $m['keyword'], 'direction' => $direction,
                ]);

                // Live toast + badge + feed in every admin window. The room is
                // the domain's SHARED one (DataChanged normalizes it), but
                // only admin UIs listen for 'keyword-alerts'.
                DataChanged::send($domain, $user, 'keyword-alerts', 'triggered', $log->id, [
                    'log_id'       => $log->id,
                    'rule_id'      => $rule->id,
                    'rule_name'    => $rule->name,
                    'keyword'      => (string) ($m['keyword'] ?? ''),
                    'direction'    => $direction,
                    'text'         => mb_substr($text, 0, 240),
                    'counterparty' => $counterparty,
                    'sms_number'   => $smsNumber,
                    'session_id'   => $sessionId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('KeywordAlert failed: ' . $e->getMessage());
        }
    }
}

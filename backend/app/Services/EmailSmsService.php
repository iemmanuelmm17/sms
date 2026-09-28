<?php

namespace App\Services;

use App\Events\DataChanged;
use App\Models\AuditLog;
use App\Models\EmailSmsNotification;
use App\Models\EmailSmsSender;
use App\Models\SentMessageLog;
use App\Models\Tenant;
use App\Services\Mail\ImapClient;
use App\Services\Mail\MailParser;
use App\Services\Mail\SmtpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Email↔SMS gateway orchestrator. Three flows:
 *   1. Inbound SMS → notification email (per-company toggle + address).
 *   2. Email to {destination}@domain (subject = sending number,
 *      blank = the sender's default number) → SMS, from authorized
 *      senders only; rejected senders get a "not authorized" email.
 *   3. Reply to a notification email (Reply-To is {texter}@domain,
 *      subject preset to our number) → same auth + routing as flow 2,
 *      threaded into the original session when it is still known.
 * Sends ride the tenant's stored Dynalink credential (same path as
 * agents). Email-originated SMS honors opt-outs and cost caps, and
 * lands in Reporting under email_sms. Logs carry metadata only —
 * never message text.
 */
class EmailSmsService
{
    public const MAX_FETCH = 2097152; // skip raw messages over 2 MB
    public const MAX_POLL = 25;       // messages per folder per poll
    public const MAX_SMS = 2000;      // outbound text cap
    public const FAIL_LIMIT = 3;      // poison-message give-up
    public const NOTIFY_DAYS = 90;    // reply window / prune age
    public const PURGE_DAYS = 7;        // inbound mail older than this is deleted

    public function __construct(
        protected DynalinkService $dynalink,
        protected CompanySettingsService $company,
        protected OptOutService $optouts,
    ) {}

    // ---------- gateway config (DB override → .env) ----------

    public function smtpConfig(): array
    {
        $c = config('services.mail', []);
        return [
            'host' => (string) (Settings::get('mail.smtp.host', $c['smtp_host'] ?? '') ?? ''),
            'port' => (int) (Settings::get('mail.smtp.port', $c['smtp_port'] ?? 587) ?: 587),
            'encryption' => strtolower((string) (Settings::get('mail.smtp.encryption', $c['smtp_encryption'] ?? 'tls') ?? 'tls')) ?: 'tls',
            'username' => (string) (Settings::get('mail.smtp.username', $c['smtp_username'] ?? '') ?? ''),
            'password' => (string) (Settings::get('mail.smtp.password', $c['smtp_password'] ?? '') ?? ''),
            'from_address' => (string) (Settings::get('mail.from.address', $c['from_address'] ?? '') ?? ''),
            'from_name' => (string) (Settings::get('mail.from.name', $c['from_name'] ?? '') ?? ''),
        ];
    }

    public function imapConfig(): array
    {
        $c = config('services.mail', []);
        return [
            'host' => (string) (Settings::get('mail.imap.host', $c['imap_host'] ?? '') ?? ''),
            'port' => (int) (Settings::get('mail.imap.port', $c['imap_port'] ?? 993) ?: 993),
            'encryption' => strtolower((string) (Settings::get('mail.imap.encryption', $c['imap_encryption'] ?? 'ssl') ?? 'ssl')) ?: 'ssl',
            'username' => (string) (Settings::get('mail.imap.username', $c['imap_username'] ?? '') ?? ''),
            'password' => (string) (Settings::get('mail.imap.password', $c['imap_password'] ?? '') ?? ''),
        ];
    }

    public function inboundDomain(): string
    {
        return strtolower(trim((string) (Settings::get('mail.inbound_domain',
            config('services.mail.inbound_domain', '')) ?? '')));
    }

    /** Cost caps (0 = unlimited). Defaults: 100/sender/day, 10/dest/hour. */
    public function caps(): array
    {
        $c = config('services.mail', []);
        $s = Settings::get('mail.cap_sender_daily', $c['cap_sender_daily'] ?? 100);
        $d = Settings::get('mail.cap_dest_hourly', $c['cap_dest_hourly'] ?? 10);
        return ['sender_daily' => max(0, (int) $s), 'dest_hourly' => max(0, (int) $d)];
    }

    public function sendingReady(): bool
    {
        $c = $this->smtpConfig();
        return $c['host'] !== '' && str_contains($c['from_address'], '@');
    }

    public function inboxReady(): bool
    {
        $c = $this->imapConfig();
        return $c['host'] !== '' && $c['username'] !== ''
            && $c['password'] !== '' && $this->inboundDomain() !== '';
    }

    /** Throwing test-send for the superadmin Test button. */
    public function testSend(string $to): void
    {
        $c = $this->smtpConfig();
        if ($c['host'] === '' || !str_contains($c['from_address'], '@')) {
            throw new \RuntimeException('Outbound mail is not configured (SMTP host + from address).');
        }
        $mime = SmtpClient::buildText($c['from_address'], $c['from_name'], [$to],
            'Email gateway test',
            'This is a test email from your SMS app, sent at ' . now()->format('M d, Y g:i A')
            . ".\n\nIf you received this, outbound mail is working.");
        (new SmtpClient($c['host'], $c['port'], $c['encryption'], $c['username'], $c['password']))
            ->send($c['from_address'], [$to], $mime);
    }

    /** Throwing inbox check — returns folder => unseen count. */
    public function checkInbox(): array
    {
        if (!$this->inboxReady()) {
            throw new \RuntimeException('Inbound mail is not configured (IMAP host/user/password + inbound domain).');
        }
        $c = $this->imapConfig();
        $imap = new ImapClient($c['host'], $c['port'], $c['encryption'], $c['username'], $c['password']);
        $out = [];
        try {
            $imap->open();
            foreach ($this->company->mailFolderList() as $folder) {
                try {
                    $imap->select($folder);
                    $out[$folder] = count($imap->unseenUids());
                } catch (\Throwable $e) {
                    $out[$folder] = 'error: ' . $e->getMessage();
                }
            }
            return $out;
        } finally {
            $imap->close();
        }
    }

    // ---------- flow 1: inbound SMS → notification email ----------

    /** Never throws — a notification failure must never break SMS receipt. */
    public function notifySmsReceived(string $domain, string $user, array $ev): void
    {
        try {
            if (!$this->sendingReady()) return;
            if ($domain === '') return;
            $remote = $this->digits((string) ($ev['from-number'] ?? ''));
            $number = (string) ($ev['dialed'] ?? '');
            if ($remote === '' || $number === '') return;
            $tos = $this->company->numberNotifyEmails($domain, $this->digits($number));
            if ($tos === []) return;
            $text = trim((string) ($ev['text'] ?? ''));
            if ($text === '' && !empty($ev['file-access-url'])) $text = '[MMS attachment]';
            if ($text === '') return;
            // Own dedupe (both twins call us): claim only when sendable, so
            // a numberless message-first twin can't suppress the session
            // twin's mail.
            $nkey = 'emailsms:notify:' . md5(
                ($ev['messagesession-id'] ?? '') . '|' . $remote . '|' . $text);
            if (!Cache::add($nkey, 1, now()->addSeconds(60))) return;
            $smtp = $this->smtpConfig();
            $mailer = new SmtpClient($smtp['host'], $smtp['port'], $smtp['encryption'], $smtp['username'], $smtp['password']);
            $inbound = $this->inboundDomain();
            $at = strrchr($smtp['from_address'], '@');
            $host = $at !== false ? substr($at, 1) : ($inbound !== '' ? $inbound : 'localhost');
            $co = $this->company->name($domain);
            // Subject is always our number: hitting Reply presets it,
            // so the reply routes like a fresh send (sender auth +
            // subject routing; notify-only recipients can't reply).
            $subject = $number;
            $body = "From: {$remote}\nTo: {$number}\nTime: " . now()->format('M d, Y g:i A')
                . "\n\n{$text}\n\n--\nReply to this email to text {$remote} back (authorized senders only).";
            $fromName = $smtp['from_name'] !== '' ? $smtp['from_name'] : ($co !== '' ? $co . ' SMS' : '');
            $failed = 0;
            // One row + Reply-To per recipient, so replies thread per address.
            foreach ($tos as $to) {
                $row = null;
                try {
                    $row = EmailSmsNotification::create([
                        'domain' => $domain, 'user' => $user !== '' ? $user : null,
                        'to_email' => $to, 'remote' => $remote,
                        'from_number' => $number,
                        'session_id' => $ev['messagesession-id'] ?? null,
                    ]);
                    $replyTo = $inbound !== '' ? "{$remote}@{$inbound}" : '';
                    $mid = '<smsnotify-' . $row->id . '-' . substr(md5((string) random_bytes(8)), 0, 8) . "@{$host}>";
                    $row->update(['message_id' => $mid]);
                    $mime = SmtpClient::buildText($smtp['from_address'], $fromName,
                        [$to], $subject, $body,
                        ['Message-ID' => $mid] + ($replyTo !== '' ? ['Reply-To' => $replyTo] : []));
                    $mailer->send($smtp['from_address'], [$to], $mime);
                } catch (\Throwable $e) {
                    $failed++;
                    try {
                        if ($row) $row->delete();
                    } catch (\Throwable $e2) {
                    }
                }
            }
            if ($failed > 0) Log::warning("EmailSms notify: {$failed} recipient(s) failed");
        } catch (\Throwable $e) {
            Log::warning('EmailSms notify failed: ' . $e->getMessage());
        }
    }

    // ---------- flows 2+3: poll inbox ----------

    /** Poll unseen mail in INBOX + each tenant folder. Returns stats. */
    public function pollInbox(): array
    {
        $stats = ['ready' => false, 'folders' => [], 'fetched' => 0, 'sent' => 0,
            'replies' => 0, 'skipped' => 0, 'purged' => 0, 'errors' => 0];
        if (!$this->inboxReady()) return $stats;
        $stats['ready'] = true;
        $c = $this->imapConfig();
        $imap = new ImapClient($c['host'], $c['port'], $c['encryption'], $c['username'], $c['password']);
        try {
            $imap->open();
            foreach ($this->company->mailFolderList() as $folder) {
                $stats['folders'][] = $folder;
                try {
                    $imap->select($folder);
                } catch (\Throwable $e) {
                    Log::warning('EmailSms folder select failed: ' . $e->getMessage());
                    $stats['errors']++;
                    continue;
                }
                $uids = array_slice($imap->unseenUids(), 0, self::MAX_POLL);
                foreach ($uids as $uid) {
                    $stats['fetched']++;
                    try {
                        $meta = $imap->meta($uid);
                        if ($meta['size'] > self::MAX_FETCH) {
                            $imap->addSeen($uid);
                            $stats['skipped']++;
                            continue;
                        }
                        // Stale mail is deleted, never processed: a
                        // reactivated tenant must not inherit ancient SMS.
                        if ($meta['date'] !== null && $meta['date'] < time() - self::PURGE_DAYS * 86400) {
                            try {
                                $imap->delete($uid);
                            } catch (\Throwable $e2) {
                                $imap->addSeen($uid);
                            }
                            $stats['purged']++;
                            continue;
                        }
                        $outcome = $this->processMessage(MailParser::parse($imap->fetchFull($uid)));
                        if ($outcome === 'send-failed') {
                            $fails = $this->noteFail($folder, $imap->uidValidity, $uid);
                            if ($fails >= self::FAIL_LIMIT) {
                                $imap->addSeen($uid);
                                Log::warning('EmailSms giving up on message', ['fails' => $fails]);
                            }
                            $stats['errors']++;
                        } else {
                            $imap->addSeen($uid);
                            if ($outcome === 'sent') $stats['sent']++;
                            elseif ($outcome === 'reply') $stats['replies']++;
                            else $stats['skipped']++;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('EmailSms message failed: ' . $e->getMessage());
                        $stats['errors']++;
                        try {
                            $imap->addSeen($uid);
                        } catch (\Throwable $e2) {
                        }
                    }
                }
                try {
                    $imap->expunge();
                } catch (\Throwable $e) {
                    Log::warning('EmailSms expunge failed: ' . $e->getMessage());
                }
            }
        } finally {
            $imap->close();
        }
        try {
            EmailSmsNotification::where('created_at', '<', now()->subDays(self::NOTIFY_DAYS))->delete();
        } catch (\Throwable $e) {
        }
        return $stats;
    }

    protected function noteFail(string $folder, int $validity, int $uid): int
    {
        $key = 'emailsms:fail:' . md5($folder) . ":{$validity}:{$uid}";
        $n = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $n, now()->addDay());
        return $n;
    }

    /**
     * Route one parsed message. Returns sent|reply|send-failed|capped|
     * self|auto|unauthorized|ambiguous|no-number|bad-number|opted-out|
     * empty|ignored.
     */
    protected function processMessage(array $m): string
    {
        $from = strtolower(trim($m['from']));
        if ($from === '') return 'ignored';
        if ($from === strtolower($this->smtpConfig()['from_address'])) return 'self';
        if ($m['auto']) return 'auto';
        $domain = $this->inboundDomain();
        $recips = array_merge($m['to'], $m['cc']);
        // Reply path: legacy encoded Reply-To (in-flight notifications),
        // or In-Reply-To/References fallback. Both enforce sender auth.
        foreach ($recips as $r) {
            if (preg_match('/^sms-(\d+)@' . preg_quote($domain, '/') . '$/i', strtolower(trim($r)), $mm)) {
                return $this->replySms((int) $mm[1], $from, $m['text']);
            }
        }
        $refs = array_merge($m['in_reply_to'], $m['references']);
        if ($refs !== []) {
            $hit = EmailSmsNotification::whereIn('message_id', $refs)->latest('id')->first();
            if ($hit) return $this->replySms($hit->id, $from, $m['text']);
        }
        // New-SMS path (notification replies land here too: their
        // Reply-To is {texter}@domain): first {digits}@domain wins.
        foreach ($recips as $r) {
            $r = strtolower(trim($r));
            if (!str_ends_with($r, '@' . $domain)) continue;
            $dest = $this->digits(substr($r, 0, (int) strrpos($r, '@')));
            if (strlen($dest) < 7 || strlen($dest) > 15) continue;
            return $this->newSms($from, $dest, $m['subject'], $m['text']);
        }
        return 'ignored';
    }

    /** Flow 3: verified reply → SMS in the original session. */
    protected function replySms(int $id, string $from, string $text): string
    {
        $n = EmailSmsNotification::find($id);
        // Unknown/stale ids stay silent (never backscatter to a forged
        // address); known rows get the unauthorized notice.
        if (!$n) return 'unauthorized';
        $num = $this->digits((string) $n->from_number);
        if (strtolower((string) $n->to_email) !== $from) {
            $this->sendUnauthorized($from, $num !== '' ? $num : null);
            return 'unauthorized';
        }
        // Require-auth: the replier must be an authorized sender for
        // the number, even though the notification reached them.
        if (!$this->senderMayUse($from, $num)) {
            $this->sendUnauthorized($from, $num !== '' ? $num : null);
            return 'unauthorized';
        }
        $text = $this->cleanReply($text);
        if ($text === '') return 'empty';
        $t = Tenant::where('domain', $n->domain)->first();
        if (!$t || !$t->isActive()) {
            Log::warning('EmailSms reply: no active tenant');
            return 'send-failed';
        }
        if ($this->optouts->isOptedOut($n->domain, $n->remote, $n->from_number)) return 'opted-out';
        if (!$this->checkCaps($from, $n->remote)) return 'capped';
        try {
            $token = $t->accessToken();
            $payload = ['type' => 'sms', 'message' => $text,
                'destination' => $n->remote, 'from-number' => $n->from_number];
            if ($n->session_id) {
                [$status, $body] = $this->dynalink->sendInSession(
                    $token, $n->domain, $t->dynalink_user, $n->session_id, $payload);
            } else {
                [$status, $body] = $this->dynalink->sendNew($token, $n->domain, $t->dynalink_user, $payload);
            }
        } catch (\Throwable $e) {
            Log::warning('EmailSms reply send failed: ' . $e->getMessage());
            return 'send-failed';
        }
        if ($status < 200 || $status >= 300) {
            Log::warning("EmailSms reply HTTP {$status}");
            return 'send-failed';
        }
        $this->afterSend($t, $n->domain, $from, $n->from_number, $n->remote, $body);
        $this->burnCaps($from, $n->remote);
        return 'reply';
    }

    /** Flow 2: authorized sender + assigned number → new SMS. */
    protected function newSms(string $from, string $dest, string $subject, string $text): string
    {
        // Replies arrive here too (Reply-To is {texter}@domain), so
        // strip quotes/signatures before the empty check + send.
        $text = $this->cleanReply($text);
        if ($text === '') return 'empty';
        $senders = EmailSmsSender::where('email', $from)->get();
        $want = $this->digits($subject);
        if ($want !== '' && (strlen($want) < 7 || strlen($want) > 15)) return 'bad-number';
        if ($senders->isEmpty()) {
            $this->sendUnauthorized($from, $want !== '' ? $want : null);
            return 'unauthorized';
        }
        $match = null;
        $matchNum = '';
        if ($want !== '') {
            foreach ($senders as $s) {
                $hit = $this->matchNum($want, $s->digits());
                if ($hit !== null) {
                    if ($match) return 'ambiguous';
                    $match = $s;
                    $matchNum = $hit;
                }
            }
            if (!$match) {
                $this->sendUnauthorized($from, $want);
                return 'unauthorized';
            }
        } else {
            // Blank subject: each sender row resolves its default
            // (explicit default, else the lone number). Exactly one
            // candidate may send; more is ambiguous, zero has no
            // default to use.
            foreach ($senders as $s) {
                $def = $s->defaultDigit();
                if ($def !== null) {
                    if ($match) return 'ambiguous';
                    $match = $s;
                    $matchNum = $def;
                }
            }
            if (!$match) return 'no-number';
        }
        $t = Tenant::where('domain', $match->domain)->first();
        if (!$t || !$t->isActive()) {
            Log::warning('EmailSms send: no active tenant');
            return 'send-failed';
        }
        if ($this->optouts->isOptedOut($match->domain, $dest, $matchNum)) return 'opted-out';
        if (!$this->checkCaps($from, $dest)) return 'capped';
        // Notification replies thread into the original conversation
        // when its session is still known; else a fresh send.
        $session = null;
        try {
            $hit = EmailSmsNotification::where('to_email', $from)->where('remote', $dest)->latest('id')->first();
            if ($hit && $this->matchNum($this->digits((string) $hit->from_number), [$matchNum]) !== null) {
                $session = $hit->session_id ?: null;
            }
        } catch (\Throwable $e) {
        }
        try {
            $token = $t->accessToken();
            $payload = ['type' => 'sms', 'message' => $text,
                'destination' => $dest, 'from-number' => $matchNum];
            $status = 0;
            $body = null;
            if ($session) {
                try {
                    [$status, $body] = $this->dynalink->sendInSession(
                        $token, $match->domain, $t->dynalink_user, $session, $payload);
                } catch (\Throwable $e) {
                    $status = 0;
                }
                if ($status < 200 || $status >= 300) $session = null;
            }
            if (!$session) {
                [$status, $body] = $this->dynalink->sendNew($token, $match->domain, $t->dynalink_user, $payload);
            }
        } catch (\Throwable $e) {
            Log::warning('EmailSms send failed: ' . $e->getMessage());
            return 'send-failed';
        }
        if ($status < 200 || $status >= 300) {
            Log::warning("EmailSms send HTTP {$status}");
            return 'send-failed';
        }
        $this->afterSend($t, $match->domain, $from, $matchNum, $dest, $body);
        $this->burnCaps($from, $dest);
        return 'sent';
    }

    /** Quota remaining for this sender + destination (0 cap = unlimited). */
    protected function checkCaps(string $from, string $dest): bool
    {
        $caps = $this->caps();
        if ($caps['sender_daily'] > 0) {
            $k = 'mailcap:sender:' . date('Y-m-d') . ':' . md5($from);
            if ((int) Cache::get($k, 0) >= $caps['sender_daily']) return false;
        }
        if ($caps['dest_hourly'] > 0) {
            $k = 'mailcap:dest:' . date('Y-m-d-H') . ':' . $dest;
            if ((int) Cache::get($k, 0) >= $caps['dest_hourly']) return false;
        }
        return true;
    }

    /** Burn quota after a successful send (failures don't count). */
    protected function burnCaps(string $from, string $dest): void
    {
        $caps = $this->caps();
        try {
            if ($caps['sender_daily'] > 0) {
                $k = 'mailcap:sender:' . date('Y-m-d') . ':' . md5($from);
                Cache::put($k, (int) Cache::get($k, 0) + 1, now()->addDay());
            }
            if ($caps['dest_hourly'] > 0) {
                $k = 'mailcap:dest:' . date('Y-m-d-H') . ':' . $dest;
                Cache::put($k, (int) Cache::get($k, 0) + 1, now()->addHours(2));
            }
        } catch (\Throwable $e) {
        }
    }

    /** Reporting row + UI refresh + audit. Metadata only — never the text. */
    protected function afterSend(Tenant $t, string $domain, string $from, string $num, string $dest, mixed $body): void
    {
        try {
            SentMessageLog::record([
                'tenant_id' => $t->id, 'domain' => $domain, 'user' => $t->dynalink_user,
                'agent_id' => null, 'actor_name' => 'email:' . $from,
                'category' => SentMessageLog::EMAIL_SMS,
                'session_id' => is_array($body) ? ($body['messagesession-id'] ?? $body['messagesession_id'] ?? null) : null,
                'from_number' => $num, 'to_number' => $dest, 'type' => 'sms',
            ]);
        } catch (\Throwable $e) {
        }
        try {
            DataChanged::send($domain, $t->dynalink_user, 'sessions', 'message-sent', null, ['remote' => $dest]);
        } catch (\Throwable $e) {
        }
        try {
            AuditLog::record($domain, 'email-sms', null, $from, 'email_sms.sent',
                ['from_number' => $num, 'to' => $dest], null);
        } catch (\Throwable $e) {
        }
    }

    /** Strip quotes/attribution/signatures from a reply; cap length. */
    protected function cleanReply(string $text): string
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $text) as $ln) {
            $t = trim($ln);
            if ($t === '-- ') break;
            if (str_starts_with($t, '>')) continue;
            if (preg_match('/^On .+ wrote:$/i', $t)) break;
            if (preg_match('/^-{2,}\s*Original Message\s*-{2,}/i', $t)) break;
            $out[] = $ln;
        }
        $text = trim(implode("\n", $out));
        if (mb_strlen($text) > self::MAX_SMS) $text = rtrim(mb_substr($text, 0, self::MAX_SMS - 1)) . '…';
        return $text;
    }

    /** True when $email is authorized to send from $want. */
    protected function senderMayUse(string $email, string $want): bool
    {
        if ($want === '') return false;
        foreach (EmailSmsSender::where('email', $email)->get() as $s) {
            if ($this->matchNum($want, $s->digits()) !== null) return true;
        }
        return false;
    }

    /**
     * Match $want against stored numbers: exact first, then with a
     * leading North-American 1 ignored on both sides (so a subject of
     * 5551234567 still matches a stored 15551234567). Returns the
     * stored number to send from, or null.
     */
    protected function matchNum(string $want, array $nums): ?string
    {
        if (in_array($want, $nums, true)) return $want;
        $w = (strlen($want) === 11 && str_starts_with($want, '1')) ? substr($want, 1) : $want;
        foreach ($nums as $n) {
            $c = (strlen($n) === 11 && str_starts_with($n, '1')) ? substr($n, 1) : $n;
            if ($c === $w) return $n;
        }
        return null;
    }

    /** "Not authorized" notice to a rejected sender. Never throws. */
    protected function sendUnauthorized(string $to, ?string $want): void
    {
        try {
            if (!$this->sendingReady()) return;
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return;
            $smtp = $this->smtpConfig();
            $numBit = ($want !== null && $want !== '') ? " from {$want}" : '';
            $mime = SmtpClient::buildText($smtp['from_address'], $smtp['from_name'], [$to],
                'Not authorized to send SMS by email',
                "You are not authorized to send SMS{$numBit} via email.\n\n"
                . "Contact support to request access.");
            (new SmtpClient($smtp['host'], $smtp['port'], $smtp['encryption'], $smtp['username'], $smtp['password']))
                ->send($smtp['from_address'], [$to], $mime);
        } catch (\Throwable $e) {
            Log::warning('EmailSms unauthorized notice failed: ' . $e->getMessage());
        }
    }

    protected function digits(string $v): string
    {
        return (string) preg_replace('/\D/', '', $v);
    }
}

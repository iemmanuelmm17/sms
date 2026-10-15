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
use Illuminate\Support\Facades\Http;
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
    public const MAX_MMS_ATTACH = 10485760; // MMS image attached to notification mail: 10 MB cap

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
            // Message events name the sender and our number differently from
            // session events (from_num / term_num), so take the first non-empty field.
            $remote = $this->digits($this->firstValue($ev, ['from-number', 'from_num', 'from', 'remote', 'last_sender']));
            $number = $this->firstValue($ev, ['dialed', 'term_num', 'smsani']);
            if ($remote === '' || $number === '') {
                if ($this->isMmsEvent($ev)) {
                    // Metadata only: which of the fields were present.
                    Log::info('EmailSms MMS: event skipped (no sender or number)', [
                        'has_sender' => $remote !== '', 'has_number' => $number !== '',
                    ]);
                }
                return;
            }
            $tos = $this->company->numberNotifyEmails($domain, $this->digits($number));
            if ($tos === []) return;

            // MMS: the media and the caption arrive in different events. Collect them.
            if ($this->isMmsEvent($ev)) {
                $this->mmsTwin($domain, $user, $ev, $remote, $number, $tos);
                return;
            }

            $text = trim((string) ($ev['text'] ?? ''));
            if ($text === '') return;
            // Own dedupe (both twins call us): claim only when sendable, so
            // a numberless message-first twin can't suppress the session
            // twin's mail.
            $nkey = 'emailsms:notify:' . md5(
                ($ev['messagesession-id'] ?? '') . '|' . $remote . '|' . $text);
            if (!Cache::add($nkey, 1, now()->addSeconds(60))) return;
            $this->sendNotifyEmails($domain, $user, $ev, $remote, $number, $tos, $text, null, '');
        } catch (\Throwable $e) {
            Log::warning('EmailSms notify failed: ' . $e->getMessage());
        }
    }

    /**
     * An MMS arrives as two events for one message. The message event carries
     * the media (media_contents, no caption); the session event carries the
     * caption (last_mesg, no media). Both are collected in one cache entry per
     * session + sender. The email goes out as soon as it has both pieces, or
     * after a short wait (a job), so an image-only MMS or a missing twin still
     * gets its email.
     */
    protected function mmsTwin(string $domain, string $user, array $ev, string $remote, string $number, array $tos): void
    {
        $session = trim((string) ($ev['messagesession-id'] ?? ''));
        $key = 'emailsms:mms:' . md5(($session !== '' ? $session : '-') . '|' . $remote);
        $state = Cache::get($key, []);
        if (!is_array($state)) $state = [];
        $src = $this->mediaSource($ev);
        if ($src === null && $this->hasMediaPayload($ev) && empty($state['src'])) {
            // Diagnostic (key names only, never values): the media field exists
            // but no URL or image data was found in it.
            Log::info('EmailSms MMS: media field present but no URL or image data found', [
                'shape' => $this->shapeOf($ev['media_contents'] ?? $ev['media-contents'] ?? null),
            ]);
        }
        // A finished MMS that this event does not belong to (a new media URL in the
        // same conversation) starts a fresh entry. Plain twins are ignored.
        if (!empty($state['sent'])) {
            if ($src === null || md5(json_encode($src)) === ($state['src_hash'] ?? null)) return;
            $state = [];
        }
        $state['domain'] = $domain;
        $state['user'] = $user;
        $state['remote'] = $remote;
        $state['number'] = $number;
        $state['session'] = $session;
        $state['tos'] = $tos;
        if ($src !== null && empty($state['src'])) {
            $state['src'] = $src;
            $state['src_hash'] = md5(json_encode($src));
        }
        $size = $ev['media_size'] ?? $ev['last_mesg_media_size'] ?? $ev['media-size'] ?? null;
        if ($size !== null && empty($state['size'])) $state['size'] = $size;
        $caption = trim((string) ($ev['text'] ?? $ev['last_mesg'] ?? ''));
        if ($caption !== '') $state['text'] = $caption;
        Cache::put($key, $state, now()->addMinutes(2));

        if (!empty($state['src']) && !empty($state['text'])) {
            $this->sendMms($key);      // both pieces are here: send now
            return;
        }
        if (empty($state['scheduled'])) {
            $state['scheduled'] = true;
            Cache::put($key, $state, now()->addMinutes(2));
            $this->scheduleMmsSend($key);   // wait for the twin, then send what arrived
        }
    }

    /** Send the collected MMS notification once. Called by the delayed job, or directly. */
    public function sendMms(string $key): void
    {
        try {
            $state = Cache::get($key);
            if (!is_array($state) || !empty($state['sent'])) return;
            // Atomic claim, so two workers can't both send.
            if (!Cache::add('emailsms:mmsdone:' . md5($key . '|' . ($state['src_hash'] ?? '')), 1, now()->addMinutes(2))) return;
            $state['sent'] = true;
            Cache::put($key, $state, now()->addMinutes(2));

            $image = !empty($state['src']) ? $this->fetchMmsImage($state['src'], $state['size'] ?? null) : null;
            Log::info('EmailSms MMS: ' . ($image ? 'image attached' : 'image not attached'), [
                'has_media' => !empty($state['src']), 'has_caption' => !empty($state['text']),
            ]);
            $text = (string) ($state['text'] ?? '');
            if ($text === '') $text = '[MMS attachment]';
            $note = $image
                ? "\n\nThe image from this MMS is attached as {$image['name']}."
                : "\n\nThe MMS attachment could not be included here. Open the conversation in the app to view it.";
            $this->sendNotifyEmails($state['domain'], $state['user'], ['messagesession-id' => $state['session']],
                $state['remote'], $state['number'], $state['tos'], $text, $image, $note);
        } catch (\Throwable $e) {
            Log::warning('EmailSms MMS send failed: ' . $e->getMessage());
        }
    }

    /** Queue the MMS send for a few seconds from now (lets the twin event arrive first). */
    protected function scheduleMmsSend(string $key): void
    {
        try {
            \App\Jobs\SendMmsNotification::dispatch($key)->delay(now()->addSeconds(4));
        } catch (\Throwable $e) {
            Log::warning('EmailSms MMS schedule failed: ' . $e->getMessage());
        }
    }

    /** Build and send the notification, one row + Reply-To per recipient. */
    protected function sendNotifyEmails(string $domain, string $user, array $ev, string $remote, string $number,
        array $tos, string $text, ?array $image, string $note): void
    {
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
            . "\n\n{$text}{$note}\n\n--\nReply to this email to text {$remote} back (authorized senders only).";
        $fromName = $smtp['from_name'] !== '' ? $smtp['from_name'] : ($co !== '' ? $co . ' SMS' : '');
        $failed = 0;
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
                $headers = ['Message-ID' => $mid] + ($replyTo !== '' ? ['Reply-To' => $replyTo] : []);
                $mime = $image
                    ? SmtpClient::buildWithAttachments($smtp['from_address'], $fromName, [$to], $subject, $body, [$image], $headers)
                    : SmtpClient::buildText($smtp['from_address'], $fromName, [$to], $subject, $body, $headers);
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
    }

    /** Does this event describe an MMS (media present, or type mms)? Metadata only. */
    public function isMmsEvent(array $ev): bool
    {
        foreach (['type', 'media_type', 'last_mesg_type', 'last_mesg_media_type'] as $k) {
            if (strtolower(trim((string) ($ev[$k] ?? ''))) === 'mms') return true;
        }
        foreach (['media_type', 'last_mesg_media_type', 'media-type'] as $k) {
            if (str_contains((string) ($ev[$k] ?? ''), '/')) return true; // a MIME type means media
        }
        if (!empty($ev['file-access-url'])) return true;
        if ($this->hasMediaPayload($ev)) return true;
        return (int) ($ev['media_size'] ?? $ev['last_mesg_media_size'] ?? 0) > 0;
    }

    /** First non-empty string value among $keys, or ''. */
    protected function firstValue(array $ev, array $keys): string
    {
        foreach ($keys as $k) {
            $v = trim((string) ($ev[$k] ?? ''));
            if ($v !== '') return $v;
        }
        return '';
    }

    /** Is there a non-empty media_contents field? */
    protected function hasMediaPayload(array $ev): bool
    {
        $c = $ev['media_contents'] ?? $ev['media-contents'] ?? null;
        if (is_array($c)) return $c !== [];
        return is_string($c) && trim($c) !== '' && trim($c) !== '[]';
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

    /**
     * The media source in an event: a file-access-url, or media_contents. Its
     * shape is not documented, so any http(s) string, data: URI or base64 blob
     * inside it is considered. Returns ['url' => …] or ['data' => raw bytes], or null.
     */
    protected function mediaSource(array $ev): ?array
    {
        $url = trim((string) ($ev['file-access-url'] ?? ''));
        if ($url !== '') return ['url' => $url];

        $contents = $ev['media_contents'] ?? $ev['media-contents'] ?? null;
        if (is_string($contents) && trim($contents) !== '') {
            $decoded = json_decode($contents, true);
            $contents = is_array($decoded) ? $decoded : [$contents];
        }
        $strings = [];
        $walk = function ($v) use (&$walk, &$strings) {
            if (is_array($v)) {
                foreach ($v as $x) $walk($x);
            } elseif (is_string($v) && trim($v) !== '') {
                $strings[] = trim($v);
            }
        };
        $walk($contents);

        foreach ($strings as $s) {
            if (preg_match('#^https?://#i', $s)) return ['url' => $s];
        }
        foreach ($strings as $s) {
            $b64 = null;
            if (preg_match('#^data:[^;,]*;base64,(.+)$#is', $s, $m)) {
                $b64 = $m[1];
            } elseif (strlen($s) >= 200 && preg_match('#^[A-Za-z0-9+/=\r\n]+$#', $s)) {
                $b64 = $s;
            }
            if ($b64 !== null) {
                $bin = base64_decode((string) preg_replace('/\s+/', '', $b64), true);
                if ($bin !== false && $bin !== '') return ['data' => $bin];
            }
        }
        return null;
    }

    /**
     * Get an MMS image for the notification email: from a URL (downloaded with
     * a 10 s timeout, the public-address check on every redirect hop, and the
     * size cap) or from inline data. Returns ['name', 'mime', 'data'] or null.
     * Images only. Never throws, and never logs the URL (it can be a signed link).
     */
    protected function fetchMmsImage(array $source, mixed $sizeHint = null): ?array
    {
        try {
            if ((int) $sizeHint > self::MAX_MMS_ATTACH) return null;
            if (isset($source['data'])) {
                $data = (string) $source['data'];
                $mime = $this->sniffMime($data);
            } else {
                $url = (string) ($source['url'] ?? '');
                if (!$this->isPublicHttpUrl($url)) {
                    Log::warning('EmailSms MMS: image URL refused (not a public http/https address)');
                    return null;
                }
                $res = Http::timeout(10)
                    ->withOptions(['stream' => true, 'allow_redirects' => [
                        'max' => 3, 'strict' => false, 'protocols' => ['http', 'https'],
                        'on_redirect' => function ($req, $resp, $uri) {
                            if (!$this->isPublicHttpUrl((string) $uri)) {
                                throw new \RuntimeException('MMS redirect refused');
                            }
                        },
                    ]])
                    ->withHeaders(['Accept' => 'image/*'])
                    ->get($url);
                if (!$res->successful()) {
                    Log::warning('EmailSms MMS: image download HTTP ' . $res->status());
                    return null;
                }
                $ctype = strtolower(trim(explode(';', (string) $res->header('Content-Type'))[0]));
                // Stop before reading the body when the server says it is not an image.
                if ($ctype !== '' && !str_starts_with($ctype, 'image/') && $ctype !== 'application/octet-stream') {
                    Log::info('EmailSms MMS: download is not an image (content-type ' . $ctype . ')');
                    return null;
                }
                if ((int) $res->header('Content-Length') > self::MAX_MMS_ATTACH) return null;
                $stream = $res->toPsrResponse()->getBody();
                $data = '';
                while (!$stream->eof() && strlen($data) <= self::MAX_MMS_ATTACH) {
                    $data .= $stream->read(65536);
                }
                $mime = str_starts_with($ctype, 'image/') ? $ctype : $this->sniffMime($data);
            }
            if ($data === '' || strlen($data) > self::MAX_MMS_ATTACH) return null;
            if (!str_starts_with($mime, 'image/')) {
                Log::info('EmailSms MMS: content is not an image');
                return null;
            }
            if ($mime === 'image/jpg') $mime = 'image/jpeg';
            $ext = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/heic' => 'heic',
                'image/heif' => 'heif',
                default => 'img',
            };
            return [
                'name' => 'mms-image-' . now()->format('Ymd-His') . '.' . $ext,
                'mime' => $mime,
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            Log::warning('EmailSms MMS: image download failed');
            return null;
        }
    }

    /** Key names and value types only (never values) — for diagnosing unknown payload shapes. */
    protected function shapeOf(mixed $v, int $depth = 0): string
    {
        if (is_array($v)) {
            if ($depth > 2) return 'array';
            $keys = array_is_list($v) ? 'list[' . count($v) . ']' : 'map{' . implode(',', array_slice(array_keys($v), 0, 10)) . '}';
            $first = $v === [] ? null : reset($v);
            return $keys . (is_array($first) ? ' of ' . $this->shapeOf($first, $depth + 1) : ' of ' . gettype($first));
        }
        if (is_string($v)) return 'string(' . strlen($v) . ')';
        return gettype($v);
    }

    /** Content-sniff the bytes when the server's Content-Type is missing or generic. */
    protected function sniffMime(string $data): string
    {
        if (!class_exists(\finfo::class)) return '';
        return strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($data));
    }

    /**
     * http(s) only, and every resolved address must be public. The URL comes
     * from a webhook payload, so this keeps the server from being pointed at
     * internal hosts. IPv6 literals are refused outright.
     */
    protected function isPublicHttpUrl(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)) return false;
        $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
        if ($host === '' || str_contains($host, ':')) return false;
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) return false;
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        }
        return true;
    }

    protected function digits(string $v): string
    {
        return (string) preg_replace('/\D/', '', $v);
    }
}

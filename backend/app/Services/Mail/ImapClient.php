<?php

namespace App\Services\Mail;

/** Thrown for any IMAP failure (connect, TLS, auth, protocol). */
class ImapException extends \RuntimeException {}

/**
 * Minimal IMAP client: login, select, unseen search, full fetch,
 * flag. Raw bytes only — parsing lives in MailParser.
 */
class ImapClient
{
    public int $uidValidity = 0;

    protected $fp = null;
    protected int $tag = 0;

    public function __construct(
        protected string $host,
        protected int $port = 993,
        protected string $encryption = 'ssl', // ssl (implicit) | tls (STARTTLS) | none
        protected string $username = '',
        protected string $password = '',
        protected int $timeout = 20,
    ) {}

    public function __destruct()
    {
        $this->close();
    }

    public function open(): void
    {
        if ($this->host === '') throw new ImapException('IMAP host is not configured.');
        $enc = strtolower($this->encryption);
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $fp = @stream_socket_client($remote, $errno, $errstr, $this->timeout);
        if (!$fp) throw new ImapException("IMAP connect failed ({$errstr}).");
        stream_set_timeout($fp, $this->timeout);
        $this->fp = $fp;
        if (!str_starts_with((string) $this->readLine(), '* OK')) {
            throw new ImapException('IMAP greeting failed.');
        }
        if ($enc === 'tls') {
            $this->cmd('STARTTLS');
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new ImapException('IMAP STARTTLS failed.');
            }
        }
        $this->cmd('LOGIN ' . $this->quoted($this->username) . ' ' . $this->quoted($this->password));
    }

    /** SELECT a mailbox. Returns the message count. */
    public function select(string $mailbox = 'INBOX'): int
    {
        [$lines] = $this->cmd('SELECT ' . $this->quoted($mailbox));
        $count = 0;
        foreach ($lines as $line) {
            if (preg_match('/^\* (\d+) EXISTS/', $line, $m)) $count = (int) $m[1];
            if (preg_match('/UIDVALIDITY (\d+)/', $line, $m)) $this->uidValidity = (int) $m[1];
        }
        return $count;
    }

    /** UIDs of unseen messages, oldest first. */
    public function unseenUids(): array
    {
        $uids = [];
        [$lines] = $this->cmd('UID SEARCH UNSEEN');
        foreach ($lines as $line) {
            if (preg_match('/^\* SEARCH\s*(.*)$/', $line, $m) && trim($m[1]) !== '') {
                foreach (preg_split('/\s+/', trim($m[1])) as $u) {
                    if (ctype_digit($u)) $uids[] = (int) $u;
                }
            }
        }
        sort($uids);
        return $uids;
    }

    /** Size + server-received timestamp of a message. */
    public function meta(int $uid): array
    {
        $out = ['size' => 0, 'date' => null];
        [$lines] = $this->cmd("UID FETCH {$uid} (INTERNALDATE RFC822.SIZE)");
        foreach ($lines as $line) {
            if (preg_match('/RFC822\.SIZE (\d+)/', $line, $m)) $out['size'] = (int) $m[1];
            if (preg_match('/INTERNALDATE "([^"]+)"/', $line, $m)) {
                $ts = strtotime($m[1]);
                if ($ts !== false) $out['date'] = $ts;
            }
        }
        return $out;
    }

    /** Full raw RFC822 of a message. */
    public function fetchFull(int $uid): string
    {
        [, $literals] = $this->cmd("UID FETCH {$uid} (BODY[])");
        return implode('', $literals);
    }

    public function addSeen(int $uid): void
    {
        $this->cmd("UID STORE {$uid} +FLAGS (\\Seen)");
    }

    public function delete(int $uid): void
    {
        $this->cmd("UID STORE {$uid} +FLAGS (\\Deleted)");
    }

    /** Permanently remove all \Deleted messages in the selected folder. */
    public function expunge(): void
    {
        $this->cmd('EXPUNGE');
    }

    public function close(): void
    {
        try {
            if ($this->fp) {
                $this->cmd('LOGOUT');
                fclose($this->fp);
            }
        } catch (\Throwable $e) {
        } finally {
            $this->fp = null;
        }
    }

    /**
     * Send a tagged command; returns [lines, literals]. Throws on NO/BAD.
     */
    protected function cmd(string $command): array
    {
        $tag = 'A' . str_pad((string) ++$this->tag, 3, '0', STR_PAD_LEFT);
        fwrite($this->fp, $tag . ' ' . $command . "\r\n");
        $lines = [];
        $literals = [];
        while (($line = $this->readLine()) !== null) {
            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . preg_quote($tag, '/') . ' OK/i', $line)) {
                    throw new ImapException('IMAP error: ' . substr($line, 0, 200));
                }
                return [$lines, $literals];
            }
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $literals[] = $this->readBytes((int) $m[1]);
                $this->readLine(); // trailing CRLF after the literal
            }
            $lines[] = $line;
        }
        throw new ImapException('IMAP connection lost.');
    }

    /** Read one server line (loops past the chunk size for long lines). */
    protected function readLine(): ?string
    {
        if (!$this->fp) return null;
        $line = '';
        while (!str_ends_with($line, "\n")) {
            $chunk = fgets($this->fp, 8192);
            if ($chunk === false) return $line !== '' ? rtrim($line, "\r\n") : null;
            $line .= $chunk;
            if (strlen($line) > 1048576) break; // 1 MB sanity cap
        }
        return rtrim($line, "\r\n");
    }

    protected function readBytes(int $n): string
    {
        $out = '';
        while (strlen($out) < $n && !feof($this->fp)) {
            $chunk = fread($this->fp, $n - strlen($out));
            if ($chunk === false || $chunk === '') break;
            $out .= $chunk;
        }
        return $out;
    }

    protected function quoted(string $v): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }
}

<?php

namespace App\Services\Mail;

/** Thrown for any SMTP failure (connect, TLS, auth, send). */
class SmtpException extends \RuntimeException {}

/**
 * Minimal dependency-free SMTP client: implicit SSL, STARTTLS,
 * AUTH LOGIN. No extensions, no DSN — just reliable plain-text sends.
 */
class SmtpClient
{
    protected string $last = '';

    public function __construct(
        protected string $host,
        protected int $port = 587,
        protected string $encryption = 'tls', // ssl (implicit) | tls (STARTTLS) | none
        protected string $username = '',
        protected string $password = '',
        protected int $timeout = 15,
    ) {}

    /** Send one MIME message. Returns the server's final 250 reply. */
    public function send(string $from, array $to, string $mime): string
    {
        if ($this->host === '') throw new SmtpException('SMTP host is not configured.');
        if ($to === []) throw new SmtpException('No recipients.');
        $enc = strtolower($this->encryption);
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $fp = @stream_socket_client($remote, $errno, $errstr, $this->timeout);
        if (!$fp) throw new SmtpException("SMTP connect failed ({$errstr}).");
        stream_set_timeout($fp, $this->timeout);
        try {
            $this->expect($fp, [220]);
            $this->cmd($fp, 'EHLO sms-app', [250]);
            if ($enc === 'tls') {
                $this->cmd($fp, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new SmtpException('SMTP STARTTLS failed.');
                }
                $this->cmd($fp, 'EHLO sms-app', [250]);
            }
            if ($this->username !== '') {
                $this->cmd($fp, 'AUTH LOGIN', [334]);
                $this->cmd($fp, base64_encode($this->username), [334]);
                $this->cmd($fp, base64_encode($this->password), [235]);
            }
            $this->cmd($fp, "MAIL FROM:<{$from}>", [250]);
            foreach ($to as $rcpt) {
                $this->cmd($fp, "RCPT TO:<{$rcpt}>", [250, 251]);
            }
            $this->cmd($fp, 'DATA', [354]);
            $this->cmd($fp, $this->dotStuff($mime) . "\r\n.", [250]);
            $final = $this->last;
            try { $this->cmd($fp, 'QUIT', [221]); } catch (\Throwable $e) {}
            return $final;
        } finally {
            fclose($fp);
        }
    }

    /**
     * Build a plain-text MIME message. $extra carries Message-ID,
     * Reply-To, In-Reply-To, etc. (blank values skipped).
     */
    public static function buildText(string $from, string $fromName, array $to, string $subject, string $body, array $extra = []): string
    {
        $lines = array_merge(self::baseHeaders($from, $fromName, $to, $subject), [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
        ], self::extraHeaders($extra));
        return implode("\r\n", $lines) . "\r\n\r\n" . quoted_printable_encode($body);
    }

    /**
     * Build a multipart/mixed MIME message: a plain-text body plus binary
     * attachments. $attachments is a list of ['name', 'mime', 'data'] where
     * data is the raw bytes. Attachment bytes are base64-encoded, wrapped at 76 columns.
     */
    public static function buildWithAttachments(string $from, string $fromName, array $to, string $subject, string $body, array $attachments, array $extra = []): string
    {
        $boundary = 'sms-' . bin2hex(random_bytes(12));
        $lines = array_merge(self::baseHeaders($from, $fromName, $to, $subject), [
            'MIME-Version: 1.0',
            "Content-Type: multipart/mixed; boundary=\"{$boundary}\"",
        ], self::extraHeaders($extra));

        $parts = [
            "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($body),
        ];
        foreach ($attachments as $a) {
            $name = self::safeFilename((string) $a['name']);
            $mime = preg_replace('/[^A-Za-z0-9.+\/-]/', '', (string) $a['mime']) ?: 'application/octet-stream';
            $parts[] = "--{$boundary}\r\n"
                . "Content-Type: {$mime}; name=\"{$name}\"\r\n"
                . "Content-Disposition: attachment; filename=\"{$name}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode((string) $a['data']), 76, "\r\n");
        }
        $parts[] = "--{$boundary}--";

        return implode("\r\n", $lines) . "\r\n\r\n" . implode("\r\n", $parts) . "\r\n";
    }

    /** From / To / Subject / Date, shared by the text and multipart builders. */
    protected static function baseHeaders(string $from, string $fromName, array $to, string $subject): array
    {
        return [
            'From: ' . ($fromName !== '' ? mb_encode_mimeheader($fromName, 'UTF-8', 'Q', "\r\n") . " <{$from}>" : $from),
            'To: ' . implode(', ', $to),
            'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'Q', "\r\n"),
            'Date: ' . date('r'),
        ];
    }

    /** Optional headers (Message-ID, Reply-To, …); blank values are skipped. */
    protected static function extraHeaders(array $extra): array
    {
        $out = [];
        foreach ($extra as $k => $v) {
            if ($v !== '' && $v !== null) $out[] = "{$k}: {$v}";
        }
        return $out;
    }

    /** Keep attachment names header-safe: ASCII letters, digits, dot, dash, underscore. */
    protected static function safeFilename(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';
        return substr($clean !== '' ? $clean : 'attachment', 0, 100);
    }

    protected function cmd($fp, string $line, array $ok): string
    {
        fwrite($fp, $line . "\r\n");
        return $this->expect($fp, $ok);
    }

    /** Read a (possibly multiline) reply; throw unless the code is expected. */
    protected function expect($fp, array $ok): string
    {
        while (($line = fgets($fp, 4096)) !== false) {
            if (preg_match('/^(\d{3})([ -])/', $line, $m) && $m[2] === ' ') {
                $this->last = rtrim($line, "\r\n");
                if (!in_array((int) $m[1], $ok, true)) {
                    throw new SmtpException('SMTP error: ' . $this->last);
                }
                return $this->last;
            }
        }
        throw new SmtpException('SMTP connection lost.');
    }

    protected function dotStuff(string $mime): string
    {
        $mime = str_replace(["\r\n", "\r"], "\n", $mime);
        $out = [];
        foreach (explode("\n", $mime) as $line) {
            $out[] = str_starts_with($line, '.') ? '.' . $line : $line;
        }
        return implode("\r\n", $out);
    }
}

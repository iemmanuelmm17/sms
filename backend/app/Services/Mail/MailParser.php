<?php

namespace App\Services\Mail;

/**
 * Parse raw RFC822 into the fields the gateway needs. Addresses are
 * lowercased; text is plain UTF-8 (text/plain preferred, HTML fallback).
 */
class MailParser
{
    public static function parse(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $split = strpos($raw, "\n\n");
        $headRaw = $split === false ? $raw : substr($raw, 0, $split);
        $bodyRaw = $split === false ? '' : substr($raw, $split + 2);
        $h = self::headers($headRaw);
        $from = self::addrs($h['from'] ?? '');
        $parts = self::parts($h['content-type'] ?? 'text/plain', $bodyRaw, $h);
        $fromEmail = strtolower($from[0]['email'] ?? '');
        return [
            'from' => $fromEmail,
            'from_name' => $from[0]['name'] ?? '',
            'to' => array_column(self::addrs($h['to'] ?? ''), 'email'),
            'cc' => array_column(self::addrs($h['cc'] ?? ''), 'email'),
            'subject' => self::decodeWords($h['subject'] ?? ''),
            'message_id' => trim($h['message-id'] ?? ''),
            'in_reply_to' => self::ids($h['in-reply-to'] ?? ''),
            'references' => self::ids($h['references'] ?? ''),
            'auto' => self::isAuto($h, $fromEmail),
            'text' => mb_substr(self::pickText($parts), 0, 5000),
        ];
    }

    /** Unfold + split headers (lowercased names, first occurrence wins). */
    protected static function headers(string $raw): array
    {
        $h = [];
        $name = '';
        foreach (explode("\n", $raw) as $line) {
            if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
                if ($name !== '') $h[$name] .= ' ' . trim($line);
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) continue;
            $name = strtolower(trim(substr($line, 0, $pos)));
            if ($name === '' || isset($h[$name])) {
                if (isset($h[$name])) $name = '';
                continue;
            }
            $h[$name] = trim(substr($line, $pos + 1));
        }
        return $h;
    }

    protected static function decodeWords(string $v): string
    {
        if ($v === '' || !str_contains($v, '=?')) return $v;
        $d = mb_decode_mimeheader($v);
        return mb_convert_encoding($d, 'UTF-8', 'UTF-8');
    }

    /** Extract addresses (lowercased) with display names. */
    protected static function addrs(string $v): array
    {
        $out = [];
        $seen = [];
        $v = self::decodeWords($v);
        preg_match_all('/"?([^"<>@]*?)"?\s*<\s*([^<>\s@]+@[^<>\s]+)\s*>/', $v, $m, PREG_SET_ORDER);
        foreach ($m as $a) {
            $email = strtolower(trim($a[2]));
            if ($email === '' || isset($seen[$email])) continue;
            $seen[$email] = true;
            $out[] = ['email' => $email, 'name' => trim($a[1], " \t\"")];
        }
        preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $v, $b);
        foreach ($b[0] as $email) {
            $email = strtolower($email);
            if (isset($seen[$email])) continue;
            $seen[$email] = true;
            $out[] = ['email' => $email, 'name' => ''];
        }
        return $out;
    }

    /** All <id> tokens in a threading header. */
    protected static function ids(string $v): array
    {
        preg_match_all('/<[^<>\s]+>/', $v, $m);
        return $m[0];
    }

    protected static function isAuto(array $h, string $from): bool
    {
        $as = strtolower(trim($h['auto-submitted'] ?? ''));
        if ($as !== '' && $as !== 'no') return true;
        $prec = strtolower(trim($h['precedence'] ?? ''));
        if (in_array($prec, ['bulk', 'junk', 'list'], true)) return true;
        if (isset($h['x-autoreply']) || isset($h['x-autorespond'])) return true;
        $local = strtolower(substr($from, 0, (int) strpos($from . '@', '@')));
        return in_array($local, ['mailer-daemon', 'postmaster'], true);
    }

    /** Walk MIME parts into decoded leaves (nested multiparts recurse). */
    protected static function parts(string $contentType, string $body, array $h = []): array
    {
        if (preg_match('/^multipart\//i', trim($contentType))
            && preg_match('/boundary="?([^";]+)"?/i', $contentType, $m)) {
            $out = [];
            $boundary = '--' . trim($m[1]);
            foreach (explode($boundary, $body) as $i => $chunk) {
                if ($i === 0) continue; // preamble
                $chunk = ltrim($chunk, "\n");
                if (str_starts_with($chunk, '--')) break; // epilogue
                $split = strpos($chunk, "\n\n");
                $ph = $split === false ? [] : self::headers(substr($chunk, 0, $split));
                $pb = $split === false ? '' : substr($chunk, $split + 2);
                $out = array_merge($out, self::parts($ph['content-type'] ?? 'text/plain', $pb, $ph));
            }
            return $out;
        }
        return [[
            'type' => strtolower(trim(explode(';', $contentType)[0])),
            'attach' => self::isAttach(($h['content-disposition'] ?? '') . ' ' . $contentType),
            'text' => self::decodeLeaf($h, $contentType, $body),
        ]];
    }

    protected static function isAttach(string $v): bool
    {
        return (bool) preg_match('/\b(attachment|filename\s*=)\b/i', $v);
    }

    /** Transfer-decoding + charset conversion for one leaf part. */
    protected static function decodeLeaf(array $h, string $contentType, string $body): string
    {
        $enc = strtolower(trim($h['content-transfer-encoding'] ?? ''));
        if ($enc === 'base64') {
            // MIME wraps base64 at 76 cols; strict mode rejects the newlines.
            $d = base64_decode((string) preg_replace('/\s+/', '', $body), true);
            $body = $d === false ? '' : $d;
        } elseif ($enc === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        }
        $body = str_replace("\0", '', $body);
        $cs = '';
        if (preg_match('/charset="?([^";\s]+)"?/i', $contentType, $m)) $cs = trim($m[1]);
        if ($cs !== '' && strcasecmp($cs, 'utf-8') !== 0 && strcasecmp($cs, 'us-ascii') !== 0) {
            try {
                $body = mb_convert_encoding($body, 'UTF-8', $cs);
            } catch (\Throwable $e) {
                // Unknown charset — deliver raw bytes rather than nothing.
            }
        }
        return $body;
    }

    /** First non-attachment text/plain, else first text/html as text. */
    protected static function pickText(array $parts): string
    {
        foreach ($parts as $p) {
            if ($p['type'] === 'text/plain' && !$p['attach'] && trim($p['text']) !== '') {
                return trim($p['text']);
            }
        }
        foreach ($parts as $p) {
            if ($p['type'] === 'text/html' && !$p['attach'] && trim($p['text']) !== '') {
                return self::htmlToText($p['text']);
            }
        }
        return '';
    }

    protected static function htmlToText(string $html): string
    {
        $html = (string) preg_replace('/<(script|style)\b.*?<\/\1>/is', '', $html);
        $html = (string) preg_replace('/<(br|p|div|li|tr|h[1-6])\b[^>]*>/i', "\n", $html);
        $s = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
        $s = (string) preg_replace('/[ \t]+/', ' ', $s);
        return trim((string) preg_replace('/\n{3,}/', "\n\n", $s));
    }
}

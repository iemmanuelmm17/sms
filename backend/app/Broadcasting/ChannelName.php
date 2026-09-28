<?php

namespace App\Broadcasting;

/**
 * Private-channel naming for the per-account socket channel.
 *
 * Laravel channel parameters only match dot-free segments, but Dynalink
 * domains contain dots (e.g. "1234.ExampleCo") — an unsanitized channel
 * name can NEVER match `sms.{domain}.{user}` and every /broadcasting/auth
 * request fails with 403. So both sides (backend events, frontend
 * SocketContext, routes/channels.php) sanitize identically: anything that
 * isn't A–Z a–z 0–9 or "-" becomes "_".
 */
class ChannelName
{
    public static function safe(string $v): string
    {
        return preg_replace('/[^A-Za-z0-9-]/', '_', $v);
    }

    public static function account(string $domain, string $user): string
    {
        return 'sms.' . self::safe($domain) . '.' . self::safe($user);
    }
}

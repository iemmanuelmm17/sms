<?php

namespace App\Services;

/**
 * Pure date-range rules for auto-reply windows. No framework dependencies,
 * so the comparison logic can be exercised on its own.
 *
 * Bounds are wall-clock 'YYYY-MM-DD HH:MM' in the rule's timezone. Their
 * lexical order is their chronological order, which keeps comparisons simple.
 */
final class AutoReplyWindow
{
    private const PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/';

    /** A valid stored bound, or null (empty, malformed, or impossible date). */
    public static function normalize(mixed $v): ?string
    {
        if (!is_string($v)) return null;
        $s = trim($v);
        if ($s === '' || !preg_match(self::PATTERN, $s)) return null;
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $s);
        return ($d && $d->format('Y-m-d H:i') === $s) ? $s : null;
    }

    /** Start must come before end. Open bounds are always ordered. */
    public static function isOrdered(?string $from, ?string $to): bool
    {
        return $from === null || $to === null || $from < $to;
    }

    /** Is the wall-clock moment $cur inside [from, to)? A null bound is open. */
    public static function inDateRange(string $cur, ?string $from, ?string $to): bool
    {
        if ($from !== null && $cur < $from) return false;
        if ($to !== null && $cur >= $to) return false;
        return true;
    }
}

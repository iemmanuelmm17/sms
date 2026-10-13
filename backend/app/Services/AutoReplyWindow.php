<?php

namespace App\Services;

/**
 * Pure date-range rules for auto-reply windows. No framework dependencies,
 * so the comparison logic can be exercised on its own.
 *
 * Bounds are wall-clock 'YYYY-MM-DD HH:MM' in the rule's timezone. Their
 * lexical order is their chronological order, which keeps comparisons simple.
 *
 * A rule's date/time mode is a LIST of ranges: [{from, to}, ...]. The rule
 * fires when the moment falls inside any one of them.
 */
final class AutoReplyWindow
{
    /** Upper bound on ranges per rule (keeps the list readable and the check cheap). */
    public const MAX_RANGES = 20;

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

    /**
     * Clean a list of ranges from a request or from storage.
     *
     * Each row needs at least one bound, and when both are set the end must
     * come after the start. Returns the cleaned list, or a string with the
     * 422 message when a row is unusable.
     *
     * @return array<int, array{from: ?string, to: ?string}>|string
     */
    public static function cleanRanges(mixed $raw): array|string
    {
        if ($raw === null || $raw === '') return [];
        if (!is_array($raw)) return 'Date ranges are invalid.';
        if (count($raw) > self::MAX_RANGES) {
            return 'You can add up to ' . self::MAX_RANGES . ' date ranges.';
        }

        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) return 'Date ranges are invalid.';
            $from = self::normalize($row['from'] ?? null);
            $to   = self::normalize($row['to'] ?? null);
            if ($from === null && $to === null) return 'Each date range needs a start or an end.';
            if (!self::isOrdered($from, $to)) return 'Each date range must end after it starts.';
            $out[] = ['from' => $from, 'to' => $to];
        }
        return $out;
    }
}

<?php

namespace App\Services;

/**
 * Per-user idle sign-out window.
 *
 * Stored on the user's own row as `idle_timeout_hours`:
 *   1..8 → sign out after that many hours with no input in the browser
 *   0    → unlimited (never sign out for inactivity)
 *
 * The SPA enforces the window (it is an inactivity watchdog, not a server
 * session timer). The server only validates and stores the choice.
 */
final class IdleTimeout
{
    public const DEFAULT_HOURS = 1;
    public const UNLIMITED = 0;
    public const MAX_HOURS = 8;

    /** Every value a user may choose, in display order. */
    public static function options(): array
    {
        return array_merge(range(1, self::MAX_HOURS), [self::UNLIMITED]);
    }

    public static function isValid(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false
            && in_array((int) $value, self::options(), true);
    }

    /** Stored value for a model, or the default when the column is missing/unset. */
    public static function hoursFor(?object $user): int
    {
        $v = $user?->idle_timeout_hours;
        return ($v !== null && self::isValid($v)) ? (int) $v : self::DEFAULT_HOURS;
    }
}

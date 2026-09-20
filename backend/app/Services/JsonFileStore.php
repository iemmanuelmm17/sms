<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Locked JSON file access for the file-backed stores (company settings,
 * opt-outs, companies, groups). flock() serializes cross-process
 * read-modify-write so concurrent admins can't silently lose updates,
 * and readers never see torn files.
 *
 * Local-disk fast path; non-local disks fall back to plain Storage ops.
 * NEVER call back into the store for the same file inside a mutate()
 * closure (same-process flock re-entry deadlocks).
 */
class JsonFileStore
{
    protected static function useFlock(): bool
    {
        try { return ((string) config('filesystems.default', 'local') ?: 'local') === 'local'; }
        catch (\Throwable $e) { return true; }
    }

    public static function exists(string $rel): bool
    {
        if (!static::useFlock()) return Storage::exists($rel);
        return is_file(Storage::path($rel));
    }

    /** Locked shared read. Returns $default when missing/invalid. */
    public static function read(string $rel, mixed $default = null): mixed
    {
        if (!static::useFlock()) {
            if (!Storage::exists($rel)) return $default;
            $d = json_decode(Storage::get($rel), true);
            return is_array($d) ? $d : $default;
        }
        $file = Storage::path($rel);
        if (!is_file($file)) return $default;
        $fh = @fopen($file, 'r');
        if (!$fh) return $default;
        try {
            if (!flock($fh, LOCK_SH)) return $default;
            $raw = stream_get_contents($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        $d = json_decode($raw ?: '', true);
        return is_array($d) ? $d : $default;
    }

    /**
     * Atomic read-modify-write under an exclusive lock. $fn receives the
     * current array ([]) and returns the new array. With $mustExist, returns
     * null (writing nothing) when the file is missing.
     */
    public static function mutate(string $rel, callable $fn, bool $mustExist = false): mixed
    {
        if (!static::useFlock()) {
            if ($mustExist && !Storage::exists($rel)) return null;
            $cur = static::read($rel, []);
            $next = $fn(is_array($cur) ? $cur : []);
            Storage::put($rel, json_encode($next, JSON_PRETTY_PRINT));
            return $next;
        }
        $file = Storage::path($rel);
        if ($mustExist && !is_file($file)) return null;
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $fh = fopen($file, 'c+');
        if (!$fh) throw new \RuntimeException('store unavailable');
        try {
            if (!flock($fh, LOCK_EX)) throw new \RuntimeException('store busy');
            $raw = stream_get_contents($fh);
            $cur = json_decode($raw ?: '', true);
            if (!is_array($cur)) $cur = [];
            $next = $fn($cur);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($next, JSON_PRETTY_PRINT));
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        return $next;
    }

    /** Locked blind write (new UUID-keyed entities). */
    public static function put(string $rel, array $data): void
    {
        static::mutate($rel, fn() => $data);
    }

    /** Locked delete. */
    public static function delete(string $rel): void
    {
        if (!static::useFlock()) { Storage::delete($rel); return; }
        $file = Storage::path($rel);
        if (!is_file($file)) return;
        $fh = @fopen($file, 'r');
        if (!$fh) { @unlink($file); return; }
        try {
            flock($fh, LOCK_EX);
            @unlink($file);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}

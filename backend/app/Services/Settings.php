<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

/**
 * DB-backed global settings with .env-caller fallback. Fail-open by design:
 * a missing table (pre-migration) or cache outage returns the default and
 * caches nothing, so auth/token paths never fatal on settings.
 */
class Settings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $all = Cache::rememberForever('app_settings:all',
                fn() => AppSetting::all()->pluck('value', 'key')->all());
        } catch (\Throwable $e) {
            return $default;
        }
        return $all[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, null);
        if ($v === null) return $default;
        return in_array(mb_strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, mixed $value): void
    {
        AppSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('app_settings:all');
    }

    /**
     * Break-glass login state: flag on AND (no expiry OR not yet expired).
     * An expired window auto-closes itself on read.
     */
    public static function legacyLoginEnabled(): bool
    {
        if (!self::bool('auth.legacy_dynalink_login', false)) return false;
        $until = self::get('auth.legacy_dynalink_until', null);
        if ($until === null || $until === '') return true;
        if (time() < (int) $until) return true;
        try {
            AppSetting::where('key', 'auth.legacy_dynalink_login')->delete();
            AppSetting::where('key', 'auth.legacy_dynalink_until')->delete();
            Cache::forget('app_settings:all');
        } catch (\Throwable $e) {}
        return false;
    }
}

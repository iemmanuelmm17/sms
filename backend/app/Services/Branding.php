<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Product branding (superadmin-owned): app name + logo.
 * Name/flags live in AppSetting; the logo bytes live on local disk
 * (kept OUT of the app_settings:all cache, which every request loads).
 */
class Branding
{
    public const DEFAULT_NAME = 'SMS Messaging';
    public const DIR = 'brand';
    public const ALLOWED_EXT = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'];

    public static function state(): array
    {
        $name = trim((string) Settings::get('brand.app_name', ''));
        $ext = (string) Settings::get('brand.logo_ext', '');
        $has = in_array($ext, self::ALLOWED_EXT, true) && is_string(self::logoPath());
        $v = (string) Settings::get('brand.logo_updated_at', '');
        return [
            'app_name' => $name !== '' ? mb_substr($name, 0, 60) : self::DEFAULT_NAME,
            'has_logo' => $has,
            'logo_url' => $has ? ('/api/branding/logo?v=' . ($v !== '' ? $v : '1')) : null,
        ];
    }

    /** Absolute logo path, or null when no valid logo is stored. */
    public static function logoPath(): ?string
    {
        $ext = (string) Settings::get('brand.logo_ext', '');
        if (!in_array($ext, self::ALLOWED_EXT, true)) return null;
        $path = Storage::disk('local')->path(self::DIR . "/logo.{$ext}");
        return is_file($path) ? $path : null;
    }

    public static function setName(mixed $name): void
    {
        $name = trim((string) $name);
        if ($name === '') {
            AppSetting::where('key', 'brand.app_name')->delete();
            Cache::forget('app_settings:all');
            return;
        }
        Settings::set('brand.app_name', mb_substr($name, 0, 60));
    }

    public static function saveLogo(UploadedFile $file): void
    {
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'png');
        if (!in_array($ext, self::ALLOWED_EXT, true)) $ext = 'png';
        self::clearFiles();
        $file->storeAs(self::DIR, "logo.{$ext}", 'local');
        Settings::set('brand.logo_ext', $ext);
        // Milliseconds, so two uploads in the same second still change the URL
        // (the logo is served with a 1-day browser cache).
        Settings::set('brand.logo_updated_at', (string) (int) (microtime(true) * 1000));
    }

    public static function clearLogo(): void
    {
        self::clearFiles();
        AppSetting::whereIn('key', ['brand.logo_ext', 'brand.logo_updated_at'])->delete();
        Cache::forget('app_settings:all');
    }

    protected static function clearFiles(): void
    {
        foreach (self::ALLOWED_EXT as $e) {
            try { Storage::disk('local')->delete(self::DIR . "/logo.{$e}"); } catch (\Throwable $ex) {}
        }
    }
}

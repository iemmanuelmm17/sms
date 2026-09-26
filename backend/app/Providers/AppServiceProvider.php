<?php

namespace App\Providers;

use App\Services\Settings;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Superadmin realtime overrides (Super → Settings → Realtime broadcast)
        // take precedence over .env so the broadcast host/port/key can move
        // without redeploying config. Fail-open: Settings::get returns null
        // when the settings table is missing, leaving the .env values alone.
        $host = Settings::get('reverb.host');
        if (is_string($host) && trim($host) !== '') {
            config(['broadcasting.connections.reverb.host' => trim($host)]);
        }
        $port = Settings::get('reverb.port');
        if ($port !== null && trim((string) $port) !== '' && (int) $port > 0) {
            config(['broadcasting.connections.reverb.port' => (int) $port]);
        }
        $scheme = Settings::get('reverb.scheme');
        if (in_array($scheme, ['http', 'https'], true)) {
            config([
                'broadcasting.connections.reverb.scheme' => $scheme,
                'broadcasting.connections.reverb.useTLS' => $scheme === 'https',
            ]);
        }
        $key = Settings::get('reverb.app_key');
        if (is_string($key) && trim($key) !== '') {
            config(['broadcasting.connections.reverb.key' => trim($key)]);
        }
    }
}

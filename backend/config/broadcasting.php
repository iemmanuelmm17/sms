<?php

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        // Realtime (Reverb) — the WebSocket server browsers join. Super →
        // Global settings can override host/port/scheme/key at runtime
        // (AppServiceProvider applies the DB values on top of this file).
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),

            // CRITICAL: when Laravel builds the broadcaster it hands ONLY
            // this nested `options` array to the Pusher SDK
            // (BroadcastManager::pusher → new Pusher($key, $secret, $app_id,
            // $config['options'] ?? [])). Host/port/scheme declared at the
            // top level are silently IGNORED server-side — the SDK then uses
            // its built-in default host, api-mt1.pusher.com (Pusher's
            // cloud), which rejects the local Reverb key with
            // "auth_key should be a valid app key". This nested block is
            // what makes broadcast() actually reach the local Reverb
            // server. Never add a `cluster` option: it also redirects
            // publishes to Pusher cloud (api-{cluster}.pusher.com).
            'options' => [
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => (int) env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],

            // Top-level mirrors of the same values for legacy readers
            // (e.g. BrandingController@realtime / SuperAdminController).
            // The Pusher SDK never sees these — `options` above is the
            // source of truth for server-side broadcasting.
            // AppServiceProvider keeps BOTH places in sync when Super →
            // Global settings overrides are present.
            'host' => env('REVERB_HOST', '127.0.0.1'),
            'port' => (int) env('REVERB_PORT', 8080),
            'scheme' => env('REVERB_SCHEME', 'http'),
            'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];

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
            'host' => env('REVERB_HOST', '127.0.0.1'),
            'port' => env('REVERB_PORT', 8080),
            'scheme' => env('REVERB_SCHEME', 'http'),
            'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            'cluster' => env('REVERB_CLUSTER', 'default'),
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];

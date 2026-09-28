<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->prefix('api')->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // 1. Force CORS globally at the absolute top of the request stack
        $middleware->prepend([
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Exclude CSRF checks for external broadcasting & API calls if needed
        $middleware->validateCsrfTokens(except: ['api/*', 'broadcasting/*']);
        
        $middleware->alias([
            'broadcast.user' => \App\Http\Middleware\ResolveBroadcastUser::class,
        ]);
		
        // ONE call: two separate trustProxies() calls made the second
        // override the first, silently un-trusting proxies. Behind any
        // reverse proxy/tunnel $request->ip() was then the proxy's IP and
        // the webhook IP allowlist failed closed (403 on every inbound).
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO |
                Request::HEADER_X_FORWARDED_AWS_ELB
        );
    })
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', \App\Http\Middleware\ResolveBroadcastUser::class]]
    )
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

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
        // ---- Per-tenant API rate limits ------------------------------------
        // Bare ThrottleRequests (':30,1') keys by IP because the custom
        // sessions never populate Laravel's auth guard — one office (or every
        // tenant behind one reverse proxy) shared a single bucket. These
        // named limiters partition by WORKSPACE: Dynalink admin session →
        // domain; portal agent → the agent's tenant domain; Sanctum v1 →
        // token owner; anything unauthenticated → IP.
        $bucket = function (\Illuminate\Http\Request $r): string {
            $s = $r->session()->get('dynalink');
            if (!empty($s['domain'])) return 'dom:' . strtolower((string) $s['domain']);
            $p = $r->session()->get('agent_portal');
            if (!empty($p['id'])) {
                $d = \App\Models\AgentIdentity::whereKey($p['id'])->value('domain');
                if ($d) return 'dom:' . strtolower((string) $d);
                return 'agent:' . (int) $p['id'];
            }
            if ($u = $r->user()) return 'usr:' . $u->getAuthIdentifier();
            return 'ip:' . (string) $r->ip();
        };
        \Illuminate\Support\Facades\RateLimiter::for('tenant', function (\Illuminate\Http\Request $r) use ($bucket) {
            // Generous blanket limit: catches runaway loops/scrapers without
            // ever throttling real usage (a page load fires ~10-20 calls).
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(240)->by($bucket($r))
                ->response(fn () => response()->json(['message' => 'Too many requests — please slow down.'], 429));
        });
        \Illuminate\Support\Facades\RateLimiter::for('tenant-send', function (\Illuminate\Http\Request $r) use ($bucket) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by($bucket($r))
                ->response(fn () => response()->json(['message' => 'Sending limit reached (30/min per workspace). Wait a few seconds and try again.'], 429));
        });
        \Illuminate\Support\Facades\RateLimiter::for('login', function (\Illuminate\Http\Request $r) {
            // Brute-force guard that survives office NAT: tight per
            // IP+account, loose per IP so a whole team can log in at 9am.
            $who = (string) ($r->input('username') ?? $r->input('user') ?? $r->input('email') ?? $r->input('ext') ?? '');
            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by((string) $r->ip() . '|' . mb_strtolower($who))
                    ->response(fn () => response()->json(['message' => 'Too many login attempts for this account — wait a minute.'], 429)),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by((string) $r->ip())
                    ->response(fn () => response()->json(['message' => 'Too many login attempts from this address — wait a minute.'], 429)),
            ];
        });

        // ---- Queue-failure alerting ----------------------------------------
        // The database driver already persists failed_jobs rows, but the
        // exception was invisible unless you queried that table. Log a
        // greppable line (same convention as AutoReply:/KeywordAlert:).
        \Illuminate\Support\Facades\Queue::failing(function (\Illuminate\Queue\Events\JobFailed $event) {
            \Illuminate\Support\Facades\Log::error('queue:job-failed', [
                'connection' => $event->connectionName,
                'job' => $event->job?->resolveName(),
                'uuid' => $event->job?->uuid(),
                'attempts' => $event->job?->attempts(),
                'error' => (string) $event->exception,
            ]);
        });

        // ---- Stale-cache guard for long-lived queue workers ----------------
        // OptOutService memoizes the DNC list per process. queue:work runs
        // forever, so without this a STOP recorded in the web process could
        // stay invisible to scheduled/recurring sends until worker restart.
        \Illuminate\Support\Facades\Queue::looping(function () {
            \App\Services\OptOutService::flushMemo();
        });

        // Superadmin realtime overrides (Super → Settings → Realtime broadcast)
        // take precedence over .env so the broadcast host/port/key can move
        // without redeploying config. Fail-open: Settings::get returns null
        // when the settings table is missing, leaving the .env values alone.
        // NOTE: the Pusher SDK only ever sees `options.*` (see
        // config/broadcasting.php), so every override is written there AND
        // to the top-level mirrors used by /api/realtime + Super admin UI.
        $host = Settings::get('reverb.host');
        if (is_string($host) && trim($host) !== '') {
            config([
                'broadcasting.connections.reverb.options.host' => trim($host),
                'broadcasting.connections.reverb.host' => trim($host),
            ]);
        }
        $port = Settings::get('reverb.port');
        if ($port !== null && trim((string) $port) !== '' && (int) $port > 0) {
            config([
                'broadcasting.connections.reverb.options.port' => (int) $port,
                'broadcasting.connections.reverb.port' => (int) $port,
            ]);
        }
        $scheme = Settings::get('reverb.scheme');
        if (in_array($scheme, ['http', 'https'], true)) {
            config([
                'broadcasting.connections.reverb.options.scheme' => $scheme,
                'broadcasting.connections.reverb.options.useTLS' => $scheme === 'https',
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

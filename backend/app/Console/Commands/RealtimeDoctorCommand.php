<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Services\Settings;
use Illuminate\Console\Command;

/**
 * php artisan realtime:doctor — pin-point why broadcasts fail.
 *
 * Three independent layers can disagree about the Reverb credentials, and the
 * bare log line "auth_key should be a valid app key" does not say which:
 *
 *   1. backend/.env on disk — what freshly booted processes use, and what the
 *      Reverb SERVER itself uses (it reads .env only when it starts).
 *   2. DB overrides (app_settings `reverb.*`, Super → Settings → Realtime) —
 *      they beat .env for the BROADCASTER only, they travel with migrated
 *      databases, and they can also sit stale in the settings CACHE.
 *   3. The RUNNING Reverb process — if the key changed after it booted, it is
 *      still validating the old one. Same story for queue workers.
 *
 * This command reads all three, cross-checks them, and live-probes the socket
 * server with the Pusher HTTP API (GET /channels) — first THROUGH the exact
 * broadcaster instance Laravel itself uses (ground truth: it reveals when the
 * SDK is secretly talking to api-mt1.pusher.com because host/port are not
 * nested inside the reverb connection's `options` array), then directly with
 * the effective credentials, then with the pure .env ones. The combination of
 * the probe results identifies the broken layer exactly.
 */
class RealtimeDoctorCommand extends Command
{
    protected $signature = 'realtime:doctor';
    protected $description = 'Diagnose realtime broadcast failures (env vs DB overrides vs the running Reverb server)';

    public function handle(): int
    {
        $problems = [];

        $this->line('');
        $this->info('REALTIME DOCTOR');
        $this->line(str_repeat('=', 60));

        // ---------- [1] .env on disk (parsed raw: env() lies under config:cache) ----------
        $env = $this->readEnvFile(base_path('.env'));
        $envKey    = (string) ($env['REVERB_APP_KEY'] ?? '');
        $envSecret = (string) ($env['REVERB_APP_SECRET'] ?? '');
        $envAppId  = (string) ($env['REVERB_APP_ID'] ?? '');
        $envHost   = (string) ($env['REVERB_HOST'] ?? '127.0.0.1');
        $envPort   = (int) ($env['REVERB_PORT'] ?? 8080);
        $envScheme = (string) ($env['REVERB_SCHEME'] ?? 'http');
        $envConn   = (string) ($env['BROADCAST_CONNECTION'] ?? '');

        $this->line('');
        $this->comment('[1] backend/.env on disk');
        $this->kv('BROADCAST_CONNECTION', $envConn !== '' ? $envConn : '(unset — defaults to "null" = broadcasting OFF)');
        if ($envConn !== 'reverb') $problems[] = 'BROADCAST_CONNECTION in .env is "' . ($envConn ?: 'unset') . '" — must be "reverb".';
        $this->kv('REVERB_APP_ID', $envAppId ?: '(MISSING)');
        $this->kv('REVERB_APP_KEY', $envKey !== '' ? $this->mask($envKey) : '(MISSING)');
        $this->kv('REVERB_APP_SECRET', $envSecret !== '' ? $this->mask($envSecret) : '(MISSING)');
        $this->kv('REVERB_HOST:PORT', $envHost . ':' . $envPort . ' (' . $envScheme . ')');
        foreach (['REVERB_APP_KEY' => $envKey, 'REVERB_APP_SECRET' => $envSecret, 'REVERB_APP_ID' => $envAppId] as $name => $val) {
            if ($val === '') $problems[] = "$name is empty in backend/.env.";
        }
        if ($envKey !== '' && !preg_match('/^[A-Za-z0-9_\-]+$/', $envKey)) {
            $this->error('    ✗ REVERB_APP_KEY contains characters outside [A-Za-z0-9_-] (e.g. base64 "+"/"=").');
            $this->line('      The Pusher HTTP API query string is NOT URL-encoded by the SDK: "+" arrives');
            $this->line('      at Reverb as a space and EVERY broadcast dies with "auth_key should be a valid app key".');
            $problems[] = 'REVERB_APP_KEY must be letters/digits only — regenerate it (see DEPLOYMENT.md §2.2).';
        } else {
            $this->ok('    ✓ key format is URL-safe (letters/digits only)');
        }

        // ---------- [2] DB overrides + settings cache ----------
        $this->line('');
        $this->comment('[2] DB overrides (app_settings — Super → Settings → Realtime)');
        $rows = [];
        $staleCache = false;
        try {
            $rows = AppSetting::where('key', 'like', 'reverb.%')->pluck('value', 'key')->all();
            // Settings::get() may answer from a migrated/stale cache — compare.
            foreach (['reverb.app_key', 'reverb.host', 'reverb.port', 'reverb.scheme'] as $k) {
                if ((string) Settings::get($k, '') !== (string) ($rows[$k] ?? '')) { $staleCache = true; break; }
            }
        } catch (\Throwable $e) {
            $this->warn('    ! cannot read app_settings: ' . $e->getMessage());
        }
        $activeOverrides = array_filter($rows, fn ($v) => trim((string) $v) !== '');
        if (empty($activeOverrides)) {
            $this->ok('    ✓ none — .env wins');
        } else {
            foreach ($activeOverrides as $k => $v) {
                $shown = $k === 'reverb.app_key' ? $this->mask((string) $v) : (string) $v;
                $this->kv('    ' . $k, $shown . '   (OVERRIDES .env for the broadcaster)');
            }
            if ((string) ($rows['reverb.app_key'] ?? '') !== '' && (string) $rows['reverb.app_key'] !== $envKey) {
                $problems[] = 'DB override reverb.app_key differs from .env — broadcaster and Reverb disagree. Fix: php artisan app:setting reverb.app_key --forget';
            }
            $this->line('      Clear with: php artisan app:setting reverb.app_key --forget   (same for host/port/scheme)');
        }
        if ($staleCache) {
            $this->error('    ✗ the settings CACHE disagrees with the app_settings table (migrated/stale cache).');
            $problems[] = 'Stale settings cache — run: php artisan cache:clear';
        }

        // ---------- [3] Effective broadcaster config (what serve/queue actually use) ----------
        $this->line('');
        $this->comment('[3] Effective broadcaster config (after overrides — what requests/jobs use)');
        $default = (string) config('broadcasting.default');
        $cfg = config('broadcasting.connections.reverb', []);
        // The Pusher SDK only ever sees the nested `options` block, so that
        // is the effective truth; top-level values are legacy mirrors.
        $cfgOpts = is_array($cfg['options'] ?? null) ? $cfg['options'] : [];
        $cfgKey  = (string) ($cfg['key'] ?? '');
        $cfgHost = (string) ($cfgOpts['host'] ?? $cfg['host'] ?? '');
        $cfgPort = (int) ($cfgOpts['port'] ?? $cfg['port'] ?? 0);
        $cfgScheme = (string) ($cfgOpts['scheme'] ?? $cfg['scheme'] ?? 'http');
        $cfgAppId = (string) ($cfg['app_id'] ?? '');
        if (empty($cfgOpts) && ($cfg['host'] ?? '') !== '') {
            $this->error('    ✗ the reverb connection has NO nested `options` block — Laravel hands ONLY');
            $this->error('      `options` to the Pusher SDK, so host/port above are IGNORED and every');
            $this->error('      broadcast() goes to Pusher CLOUD (api-mt1.pusher.com).');
            $problems[] = 'config/broadcasting.php: host/port/scheme must be nested inside the reverb connection\'s `options` array (currently top-level only). Fix backend/config/broadcasting.php, then: php artisan config:clear';
        }
        $this->kv('default connection', $default);
        if ($default !== 'reverb') $problems[] = "Effective broadcast connection is \"$default\", not \"reverb\" — events go nowhere. Check BROADCAST_CONNECTION and run: php artisan config:clear";
        $this->kv('key', $cfgKey !== '' ? $this->mask($cfgKey) : '(MISSING)');
        $this->kv('app_id', $cfgAppId ?: '(MISSING)');
        $this->kv('host:port', $cfgHost . ':' . $cfgPort . ' (' . $cfgScheme . ')');
        if ($cfgKey !== '' && $envKey !== '' && $cfgKey !== $envKey) {
            $this->error('    ✗ effective key ≠ .env key — an override or a stale config cache is in play.');
            if (!array_key_exists('reverb.app_key', $activeOverrides)) {
                $problems[] = 'Effective key differs from .env but no DB override exists → stale CONFIG cache. Fix: php artisan config:clear';
            }
        }
        if (is_file(base_path('bootstrap/cache/config.php'))) {
            $this->warn('    ! a cached config file exists (bootstrap/cache/config.php) — after ANY .env change run: php artisan config:clear');
        }

        // ---------- [4] Ground truth: probe through the framework's OWN broadcaster ----------
        $this->line('');
        $this->comment('[4] Ground truth — probe through the broadcaster Laravel actually uses');
        $driverProbe = ['ok' => false, 'err' => 'not attempted'];
        $driverTarget = '';
        if ($default !== 'reverb') {
            $this->line('    · skipped — default connection is "' . $default . '", not "reverb".');
        } else {
            try {
                $manager = $this->laravel->make(\Illuminate\Contracts\Broadcasting\Factory::class);
                $broadcaster = $manager->connection('reverb');
                $pusher = method_exists($broadcaster, 'getPusher') ? $broadcaster->getPusher() : null;
                if (!$pusher instanceof \Pusher\Pusher) {
                    $this->warn('    ! could not resolve the Pusher SDK instance from the broadcaster.');
                } else {
                    $st = $pusher->getSettings();
                    $dHost = (string) ($st['host'] ?? '?');
                    $dScheme = (string) ($st['scheme'] ?? 'http');
                    $dPort = $st['port'] ?? ($dScheme === 'https' ? 443 : 80);
                    $driverTarget = $dScheme . '://' . $dHost . ':' . $dPort;
                    $this->kv('    broadcaster talks to', $driverTarget);
                    if (str_contains($dHost, 'pusher.com')) {
                        $this->error('    ✗ THAT IS PUSHER CLOUD — not your Reverb server!');
                        $this->error('      Laravel passes ONLY $config[\'options\'] to the Pusher SDK; without a');
                        $this->error('      nested options.host it silently defaults to api-mt1.pusher.com, which');
                        $this->error('      rejects your local key with "auth_key should be a valid app key".');
                        $problems[] = 'Framework broadcaster targets PUSHER CLOUD (' . $driverTarget . ') — config/broadcasting.php must nest host/port/scheme inside the reverb connection\'s `options` array. Update backend/, php artisan config:clear, then restart (taskkill /F /IM php.exe + start-all.bat).';
                    }
                    try {
                        $r = $pusher->get('/channels');
                        $driverProbe = ['ok' => true, 'channels' => is_object($r) ? (array) ($r->channels ?? []) : (array) $r];
                    } catch (\Throwable $e) {
                        $driverProbe = ['ok' => false, 'err' => $e->getMessage()];
                    }
                    if ($driverProbe['ok']) {
                        $this->ok('    ✓ broadcast() through the framework reached the server — publishing WORKS.');
                        $this->line('      channels currently open: ' . count($driverProbe['channels']));
                    } else {
                        $this->error('    ✗ framework probe failed: ' . $driverProbe['err']);
                    }
                }
            } catch (\Throwable $e) {
                $this->warn('    ! could not build the broadcaster: ' . $e->getMessage());
                $driverProbe = ['ok' => false, 'err' => $e->getMessage()];
            }
        }

        // ---------- [5] Direct probe of the RUNNING Reverb server ----------
        $this->line('');
        $this->comment('[5] Direct probe — GET /channels on the running Reverb server');
        $effective = $this->probe($cfgKey, (string) ($cfg['secret'] ?? ''), $cfgAppId, $cfgHost, $cfgPort, $cfgScheme);
        if ($effective['ok']) {
            $this->ok('    ✓ Reverb ACCEPTED the broadcaster credentials — realtime publishing works.');
            $this->line('      channels currently open: ' . count($effective['channels']));
            if (!$driverProbe['ok'] && $default === 'reverb' && $driverTarget !== '') {
                $this->warn('    ! ...BUT the framework broadcaster ([4]) failed targeting ' . $driverTarget);
                $this->warn('      → this probe passes host/port explicitly; broadcast() cannot. See [4].');
            }
        } else {
            $this->error('    ✗ effective credentials rejected: ' . $effective['err']);
            $sameAsEnv = ($cfgKey === $envKey && $cfgHost === $envHost && $cfgPort === $envPort && $cfgAppId === $envAppId);
            $envProbe = $sameAsEnv ? $effective : $this->probe($envKey, $envSecret, $envAppId, $envHost, $envPort, $envScheme);
            if (!$sameAsEnv) {
                $this->line('      probe with pure .env credentials: ' . ($envProbe['ok'] ? '✓ ACCEPTED' : '✗ ' . $envProbe['err']));
            }
            $this->line('');
            if ($this->isConnRefused($effective['err'])) {
                $this->line('      Diagnosis: nothing is listening on ' . $cfgHost . ':' . $cfgPort . '.');
                $this->line('      Fix: Reverb is not running (start-all.bat) or host/port point elsewhere');
                $this->line('           (check [2] overrides — a migrated DB may point at the OLD machine).');
                $problems[] = 'Reverb unreachable at ' . $cfgHost . ':' . $cfgPort . '.';
            } elseif ($envProbe['ok']) {
                $this->line('      Diagnosis: the RUNNING Reverb knows the .env key, but the broadcaster is');
                $this->line('      presenting something else (DB override [2], stale config cache [3], or a');
                $this->line('      queue worker that booted before the key change).');
                $this->line('      Fix: clear overrides (php artisan app:setting reverb.app_key --forget),');
                $this->line('           php artisan config:clear, then taskkill /F /IM php.exe + start-all.bat');
                $problems[] = 'Broadcaster credentials ≠ running Reverb credentials.';
            } else {
                $this->line('      Diagnosis: the RUNNING Reverb does not know the key in .env — it booted');
                $this->line('      BEFORE the key changed (Reverb reads .env only at startup), or its own');
                $this->line('      .env differs from backend/.env.');
                $this->line('      Fix: taskkill /F /IM php.exe   then   start-all.bat   (restarts Reverb,');
                $this->line('           serve AND the queue worker with the current .env).');
                $problems[] = 'Running Reverb server has different credentials than backend/.env — restart it.';
            }
        }

        // ---------- [6] frontend/.env (informational) ----------
        $this->line('');
        $this->comment('[6] frontend/.env (fallback for browsers; /api/realtime normally wins)');
        $fenv = $this->readEnvFile(dirname(base_path()) . '/frontend/.env');
        $fKey = (string) ($fenv['VITE_REVERB_APP_KEY'] ?? '');
        if ($fKey === '') {
            $this->line('    · VITE_REVERB_APP_KEY not set (browsers use /api/realtime — fine)');
        } elseif ($fKey === $envKey) {
            $this->ok('    ✓ matches backend key');
        } else {
            $this->warn('    ! VITE_REVERB_APP_KEY (' . $this->mask($fKey) . ') differs from backend .env — align them, then restart the vite process.');
        }

        // ---------- Verdict ----------
        $this->line('');
        $this->line(str_repeat('=', 60));
        if (empty($problems)) {
            $this->info('VERDICT: healthy — server-side broadcasting works. If a browser still misses');
            $this->line('events, hard-refresh it (Ctrl+F5) and check its console for "[realtime] channel subscribed ✓".');
            return 0;
        }
        $this->error('VERDICT: ' . count($problems) . ' problem(s) found:');
        foreach ($problems as $i => $p) $this->line('  ' . ($i + 1) . '. ' . $p);
        $this->line('');
        $this->line('After fixing, re-run: php artisan realtime:doctor');
        return 1;
    }

    /** Probe the Pusher HTTP API with the given credentials. Never throws. */
    protected function probe(string $key, string $secret, string $appId, string $host, int $port, string $scheme): array
    {
        if ($key === '' || $secret === '' || $appId === '') {
            return ['ok' => false, 'err' => 'missing credentials (key/secret/app_id)'];
        }
        if (!class_exists(\Pusher\Pusher::class)) {
            return ['ok' => false, 'err' => 'pusher/pusher-php-server not installed — run: composer install'];
        }
        try {
            $p = new \Pusher\Pusher($key, $secret, $appId, [
                'host' => $host !== '' ? $host : '127.0.0.1',
                'port' => $port > 0 ? $port : 8080,
                'scheme' => in_array($scheme, ['http', 'https'], true) ? $scheme : 'http',
                'useTLS' => $scheme === 'https',
                'timeout' => 5,
            ]);
            $r = $p->get('/channels');
            $ch = is_object($r) ? (array) ($r->channels ?? []) : (array) $r;
            return ['ok' => true, 'channels' => $ch];
        } catch (\Throwable $e) {
            return ['ok' => false, 'err' => trim($e->getMessage())];
        }
    }

    protected function isConnRefused(string $err): bool
    {
        $e = strtolower($err);
        return str_contains($e, 'connection refused') || str_contains($e, 'curl error 7')
            || str_contains($e, 'failed to connect') || str_contains($e, 'timed out')
            || str_contains($e, 'couldn\'t connect') || str_contains($e, 'connection error');
    }

    /** Parse a .env file directly — works even when config/env caches are stale. */
    protected function readEnvFile(string $path): array
    {
        $out = [];
        if (!is_file($path)) return $out;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            if (strlen($v) > 1 && in_array($v[0], ['"', "'"], true) && $v[0] === substr($v, -1)) {
                $v = substr($v, 1, -1);
            }
            $out[$k] = $v;
        }
        return $out;
    }

    protected function mask(string $v): string
    {
        $n = strlen($v);
        if ($n <= 8) return str_repeat('•', $n) . " ({$n} chars)";
        return substr($v, 0, 4) . '…' . substr($v, -3) . " ({$n} chars)";
    }

    protected function kv(string $k, string $v): void
    {
        $this->line('    ' . str_pad($k, 24) . ' = ' . $v);
    }

    protected function ok(string $m): void
    {
        $this->info($m);
    }
}

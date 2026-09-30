<?php

namespace App\Console\Commands;

use App\Jobs\SendScheduledMessage;
use App\Models\Contact;
use App\Models\ScheduledMessage;
use App\Models\SentMessageLog;
use App\Models\Tenant;
use App\Models\TenantAdmin;
use App\Services\DynalinkService;
use App\Services\LockoutService;
use App\Services\OptOutService;
use App\Services\PasswordPolicyService;
use App\Http\Middleware\EnsureSuperAdminIp;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * php artisan smoke:test — the regression gate this project never had.
 *
 * Boots a THROWAWAY SQLite database (temp file, migrated in-place), points
 * the session/cache/queue drivers at inert stores, and fires real HTTP
 * requests through the framework kernel. The live database.sqlite is never
 * opened and no outbound network call is made, so it is safe to run on a
 * production box at any time. Exits non-zero on any failure (CI gate).
 *
 * Every check maps to a bug class this project has actually shipped:
 *   - CORS pattern fed to preg_match   → 500 on every Origin-bearing POST
 *   - reverb host outside `options`    → broadcasts silently went to Pusher cloud
 *   - /broadcasting/auth without user  → must be 403, never 500
 *   - webhook from unknown IP          → must fail closed (403)
 *   - login brute force                → must lock out (423) after 3 fails
 *   - send retries                     → must never double-send a recipient
 */
class SmokeTestCommand extends Command
{
    protected $signature = 'smoke:test';

    protected $description = 'Self-contained regression suite on a throwaway database (safe on production; CI gate)';

    protected int $pass = 0;

    protected int $fail = 0;

    public function handle(): int
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'smoke_');
        $tmp = $tmpBase . '.sqlite';
        touch($tmp);

        // Inert runtime: temp DB, no session persistence, no cache bleed
        // from the live store, queue runs inline, no real broadcasting.
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $tmp,
            'session.driver' => 'array',
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ]);
        DB::purge('sqlite');

        $this->line('');
        $this->info('SMOKE TESTS (throwaway DB — live data untouched)');
        $this->line(str_repeat('=', 60));

        try {
            $this->callSilently('migrate', ['--force' => true]);

            $this->check('fresh database migrates (tenants table exists)', function () {
                return DB::connection('sqlite')->getSchemaBuilder()->hasTable('tenants')
                    ? null : 'migrate did not create the schema';
            });

            $this->check('health route GET /up returns 200', function () {
                $r = $this->req('GET', '/up');
                return $r->getStatusCode() === 200 ? null : 'got ' . $r->getStatusCode();
            });

            // ---- CORS (regression: patterns are fed raw to preg_match) ----
            $this->check('every cors.allowed_origins_patterns entry is a valid regex', function () {
                foreach ((array) config('cors.allowed_origins_patterns', []) as $p) {
                    if (@preg_match((string) $p, 'https://example.com') === false) {
                        return 'invalid pattern: ' . var_export($p, true) . ' (would 500 every Origin-bearing request)';
                    }
                }
                return null;
            });

            $this->check('OPTIONS /broadcasting/auth preflight succeeds with an Origin', function () {
                $r = $this->req('OPTIONS', '/broadcasting/auth', [], ['HTTP_ORIGIN' => 'http://localhost:5173']);
                $acao = $r->headers->get('Access-Control-Allow-Origin');
                if ($r->getStatusCode() >= 400) {
                    return 'got ' . $r->getStatusCode();
                }
                return $acao ? null : 'no Access-Control-Allow-Origin header on preflight';
            });

            $this->check('POST /broadcasting/auth with Origin is not a 500 (CORS regression)', function () {
                $r = $this->req('POST', '/broadcasting/auth', [
                    'socket_id' => '1234.1234',
                    'channel_name' => 'private-sms.smoke_test.shared',
                ], ['HTTP_ORIGIN' => 'http://localhost:5173']);
                return $r->getStatusCode() !== 500 ? null : '500 — the preg_match/CORS crash class is back';
            });

            $this->check('POST /broadcasting/auth without a session user is 403', function () {
                $r = $this->req('POST', '/broadcasting/auth', [
                    'socket_id' => '1234.1234',
                    'channel_name' => 'private-sms.smoke_test.shared',
                ], ['HTTP_ORIGIN' => 'http://localhost:5173']);
                return $r->getStatusCode() === 403 ? null : 'got ' . $r->getStatusCode() . ', expected 403';
            });

            // ---- Reverb config (regression: SDK only sees options.*) ----
            $this->check('broadcasting reverb connection nests host under options', function () {
                $o = config('broadcasting.connections.reverb.options');
                if (!is_array($o) || trim((string) ($o['host'] ?? '')) === '') {
                    return 'options.host missing — broadcasts would go to api-mt1.pusher.com';
                }
                if (str_contains((string) $o['host'], 'pusher.com')) {
                    return 'options.host points at Pusher cloud: ' . $o['host'];
                }
                return null;
            });

            $this->check('GET /api/realtime serves the same host as the broadcaster config', function () {
                $r = $this->req('GET', '/api/realtime');
                if ($r->getStatusCode() !== 200) {
                    return 'got ' . $r->getStatusCode();
                }
                $body = json_decode($r->getContent(), true) ?: [];
                $cfgHost = (string) (config('broadcasting.connections.reverb.options.host')
                    ?? config('broadcasting.connections.reverb.host') ?? '');
                if ((string) ($body['host'] ?? '') !== $cfgHost) {
                    return 'endpoint host ' . var_export($body['host'] ?? null, true) . ' ≠ config host ' . var_export($cfgHost, true);
                }
                return null;
            });

            // ---- Inbound webhook fails closed ----
            $this->check('webhook from non-allowlisted IP is rejected 403 (empty allowlist)', function () {
                $r = $this->req('POST', '/api/webhooks/dynalink', ['event' => 'noop'], ['REMOTE_ADDR' => '203.0.113.9']);
                return $r->getStatusCode() === 403 ? null : 'got ' . $r->getStatusCode() . ', expected 403 (fail closed)';
            });

            $this->check('webhook test-probe header acks 200 (superadmin Test button path)', function () {
                $r = $this->req('POST', '/api/webhooks/dynalink', ['event' => 'test'], [
                    'REMOTE_ADDR' => '203.0.113.9',
                    'HTTP_X_DYNALINK_WEBHOOK_TEST' => '1',
                ]);
                if ($r->getStatusCode() !== 200) {
                    return 'got ' . $r->getStatusCode() . ', expected the harmless test ack 200';
                }
                $b = json_decode($r->getContent(), true) ?: [];
                return !empty($b['test']) ? null : 'ack missing test:true';
            });

            // ---- Report attribution (the "mass SMS missing from Reporting" bug) ----
            $this->check('send-log attribution resolves the tenant for portal-agent sends', function () {
                $t = Tenant::create([
                    'name' => 'attr', 'domain' => 'attr.test', 'dynalink_user' => '7777',
                    'dynalink_pass' => 'x', 'main_number' => '15550001234', 'status' => 'active',
                ]);
                Cache::forget('tenant:id:attr.test:7777');
                Cache::forget('tenant:iddom:attr.test');
                $admin = SentMessageLog::tenantFor('attr.test', '7777');   // exact match
                $agent = SentMessageLog::tenantFor('attr.test', '101');    // extension — never a dynalink_user
                $legacy = SentMessageLog::tenantFor('no-such-domain.test', '1');
                $t->delete();
                Cache::forget('tenant:id:attr.test:7777');
                Cache::forget('tenant:iddom:attr.test');
                if ($admin !== (int) $t->id) {
                    return 'admin send attributed ' . var_export($admin, true) . ", expected {$t->id}";
                }
                if ($agent !== (int) $t->id) {
                    return 'portal-agent send attributed ' . var_export($agent, true)
                        . " — mass SMS would vanish from tenant reports (expected {$t->id})";
                }
                if ($legacy !== null) {
                    return 'a domain with NO tenant was attributed tenant ' . $legacy . ' — legacy rows must stay NULL';
                }
                return null;
            });

            // ---- Auto-reply partition (the "admin and agent rules don't reflect" bug) ----
            $this->check('auto-reply rules share one per-domain partition (admin + portal agent)', function () {
                $t = Tenant::create([
                    'name' => 'arule', 'domain' => 'arule.test', 'dynalink_user' => '8888',
                    'dynalink_pass' => 'x', 'main_number' => '15550005555', 'status' => 'active',
                ]);
                $err = null;
                try {
                    // The admin scope IS the tenant user; a portal agent's
                    // extension must remap into the SAME partition, so each
                    // side sees the other's rules and the webhook fires them
                    // whichever extension's line the SMS lands on.
                    $admin = \App\Services\AutoReplyService::rulePartitionUser('arule.test', '8888');
                    $agent = \App\Services\AutoReplyService::rulePartitionUser('arule.test', '102');
                    $none  = \App\Services\AutoReplyService::rulePartitionUser('no-such.test', '1');
                    if ($admin !== '8888') {
                        $err = "admin scope resolved to {$admin}, expected 8888";
                    } elseif ($agent !== '8888') {
                        $err = "portal-agent scope resolved to {$agent} — rules would sit in an invisible silo (expected 8888)";
                    } elseif ($none !== '1') {
                        $err = 'a domain with NO tenant was remapped — must fall back to the caller';
                    }
                } finally {
                    $t->delete();
                }
                return $err;
            });

            // ---- Login brute-force lockout ----
            $this->check('tenant login locks out (423) after ' . LockoutService::MAX_FAILS . ' wrong passwords', function () {
                $tenant = Tenant::create([
                    'name' => 'smoke', 'domain' => 'smoke.test', 'dynalink_user' => '9999',
                    'dynalink_pass' => 'x', 'main_number' => '15550009999', 'status' => 'active',
                ]);
                TenantAdmin::create([
                    'tenant_id' => $tenant->id, 'username' => 'admin',
                    'first_name' => 'Smoke', 'last_name' => 'Admin',
                    'password_hash' => Hash::make('correct-horse-9'), 'status' => 'active',
                    'secret_question' => 'smoke?', 'secret_answer_hash' => Hash::make('no'),
                    'session_version' => 0,
                ]);
                $last = null;
                for ($i = 0; $i <= LockoutService::MAX_FAILS; $i++) {
                    $last = $this->req('POST', '/api/tenant/login', [
                        'username' => 'admin@smoke', 'password' => 'wrong-' . $i,
                    ], ['REMOTE_ADDR' => '203.0.113.20']);
                }
                if ($last->getStatusCode() !== 423) {
                    return 'after ' . (LockoutService::MAX_FAILS + 1) . ' fails got ' . $last->getStatusCode() . ', expected 423';
                }
                return null;
            });

            // ---- Password policy ----
            $this->check('password policy rejects short and single-class passwords', function () {
                if (PasswordPolicyService::complexityError('abc12') === null) {
                    return 'accepted a 5-char password';
                }
                if (PasswordPolicyService::complexityError('abcdefgh') === null) {
                    return 'accepted a letters-only password';
                }
                if (PasswordPolicyService::complexityError('Abcd12345') !== null) {
                    return 'rejected a valid 9-char alphanumeric password';
                }
                return null;
            });

            // ---- Superadmin CIDR matcher ----
            $this->check('superadmin IP allowlist CIDR matcher', function () {
                if (!EnsureSuperAdminIp::matches('192.168.1.55', '192.168.1.0/24')) {
                    return 'did not match an IP inside the range';
                }
                if (EnsureSuperAdminIp::matches('10.0.0.1', '192.168.1.0/24')) {
                    return 'matched an IP outside the range';
                }
                return null;
            });

            // ---- Send idempotency (never double-send on retry) ----
            $this->check('scheduled send skips an already-sent message (no HTTP at all)', function () {
                $m = ScheduledMessage::create([
                    'domain' => 'smoke.test', 'user' => '9999', 'name' => 'Smoke', 'message' => 'hi',
                    'from_number' => '15550009999', 'type' => 'sms',
                    'send_at' => now()->toDateTimeString(), 'timezone' => 'UTC',
                    'targets' => ['contacts' => [], 'group_ids' => [], 'company' => false],
                    'recipients' => [['phone' => '15550001111', 'name' => 'T']],
                    'send_log' => [['phone' => '15550001111', 'ok' => true]],
                    'status' => 'sent',
                ]);
                Http::fake();
                (new SendScheduledMessage($m->id, 0))->handle(
                    app(DynalinkService::class), app(OptOutService::class)
                );
                // Not Http::assertNothingSent(): Laravel's Http assertions call
                // PHPUnit, which is a dev dependency — absent on production
                // boxes, where smoke:test must still run. recorded() is plain data.
                $sent = Http::recorded();
                return count($sent) === 0
                    ? null
                    : 'expected zero HTTP calls for an already-sent schedule, recorded ' . count($sent);
            });

            $this->check('scheduled send skips a recipient already confirmed in send_log', function () {
                $m = ScheduledMessage::create([
                    'domain' => 'smoke.test', 'user' => '9999', 'name' => 'Smoke2', 'message' => 'hi',
                    'from_number' => '15550009999', 'type' => 'sms',
                    'send_at' => now()->toDateTimeString(), 'timezone' => 'UTC',
                    'targets' => ['contacts' => [], 'group_ids' => [], 'company' => false],
                    'recipients' => [['phone' => '15550001111', 'name' => 'T']],
                    'send_log' => [['phone' => '15550001111', 'ok' => true]],
                    'status' => 'sending', // in-flight retry, not the early-return statuses
                ]);
                Http::fake();
                (new SendScheduledMessage($m->id, 0))->handle(
                    app(DynalinkService::class), app(OptOutService::class)
                );
                // PHPUnit-free assertion (see the check above).
                $sent = Http::recorded();
                return count($sent) === 0
                    ? null
                    : 'expected zero HTTP calls for a recipient already in send_log, recorded ' . count($sent);
            });

            // ---- Local PII surface (privacy commands have something to find) ----
            $this->check('contact PII rows are queryable by domain+phone', function () {
                Contact::create([
                    'domain' => 'smoke.test', 'user' => '9999',
                    'first_name' => 'Test', 'last_name' => 'Person',
                    'phone_cell' => '15550002222',
                ]);
                $hit = Contact::where('domain', 'smoke.test')
                    ->where('phone_cell', '15550002222')->first();
                return $hit ? null : 'contact lookup by domain+phone failed';
            });

            // ---- Shared contacts: one mixed list, per-row flag, directory marker ----
            $this->check('shared contacts ride the personal list, flagged end to end', function () {
                Contact::create([
                    'domain' => 'smoke.test', 'user' => '9999', 'provider_id' => 'smoke-p1',
                    'first_name' => 'Per', 'last_name' => 'Son', 'phone_cell' => '15550003333',
                    'is_shared' => 0,
                ]);
                Contact::create([
                    'domain' => 'smoke.test', 'user' => '9999', 'provider_id' => 'smoke-s1',
                    'first_name' => 'Sha', 'last_name' => 'Red', 'phone_cell' => '15550004444',
                    'is_shared' => 1,
                ]);
                $svc = app(\App\Services\ContactSyncService::class);
                // The personal endpoint returns BOTH books mixed, so the
                // local mirror must too — the flag only routes writes.
                $list = $svc->localList('smoke.test', '9999');
                $err = null;
                if (!$list->pluck('provider_id')->contains('smoke-p1') || !$list->pluck('provider_id')->contains('smoke-s1')) {
                    $err = 'local list is missing a row — the personal GET returns both books mixed';
                } elseif (empty($list->firstWhere('provider_id', 'smoke-s1')->toProviderArray()['shared'])) {
                    $err = 'toProviderArray() did not expose shared:true — the UI pill would never render';
                } elseif (!empty($list->firstWhere('provider_id', 'smoke-p1')->toProviderArray()['shared'])) {
                    $err = 'a personal row was flagged shared:true';
                } elseif (!\App\Services\ContactSyncService::isDirectoryRow(['uid' => 'abc123'])) {
                    $err = 'isDirectoryRow() missed a uid-only row — sync would drop SHARED pills';
                } elseif (\App\Services\ContactSyncService::isDirectoryRow(['unique-id' => 'xyz789'])) {
                    $err = 'isDirectoryRow() flagged a personal row as directory';
                }
                Contact::whereIn('provider_id', ['smoke-p1', 'smoke-s1'])->delete();
                return $err;
            });

            // ---- TCPA footer + send-text hygiene ----
            $this->check('TCPA footer default is the short compliance line', function () {
                $svc = app(\App\Services\CompanySettingsService::class);
                $f = $svc->tcpaFooter('smoke.test');
                return $f === \App\Services\CompanySettingsService::DEFAULT_TCPA_FOOTER
                    ? null : 'got: ' . var_export($f, true);
            });

            $this->check('send-text resolver decodes HTML entities (Msg&amp;Data → Msg&Data)', function () {
                $r = app(\App\Services\CompanySettingsService::class)
                    ->resolve('smoke.test', 'Msg frequency varies. Msg&amp;Data rates may apply.');
                return (str_contains($r, 'Msg&Data') && !str_contains($r, '&amp;'))
                    ? null : 'got: ' . var_export($r, true);
            });
        } catch (\Throwable $e) {
            $this->fail++;
            $this->error('  ✗ smoke run crashed: ' . $e->getMessage());
            $this->line('    at ' . $e->getFile() . ':' . $e->getLine());
        }

        @unlink($tmp);
        @unlink($tmpBase);

        $this->line(str_repeat('=', 60));
        if ($this->fail === 0) {
            $this->info("SMOKE: {$this->pass} passed, 0 failed.");
            return self::SUCCESS;
        }
        $this->error("SMOKE: {$this->pass} passed, {$this->fail} FAILED.");
        return self::FAILURE;
    }

    protected function check(string $name, \Closure $fn): void
    {
        try {
            $err = $fn();
            if ($err === null) {
                $this->pass++;
                $this->line('  ✓ ' . $name);
            } else {
                $this->fail++;
                $this->error('  ✗ ' . $name . ' — ' . $err);
            }
        } catch (\Throwable $e) {
            $this->fail++;
            $this->error('  ✗ ' . $name . ' — EXCEPTION: ' . $e->getMessage());
        }
    }

    protected function req(string $method, string $uri, array $params = [], array $server = []): Response
    {
        $request = Request::create($uri, $method, $params, [], [], array_merge([
            'HTTP_ACCEPT' => 'application/json',
        ], $server));
        return app(Kernel::class)->handle($request);
    }
}

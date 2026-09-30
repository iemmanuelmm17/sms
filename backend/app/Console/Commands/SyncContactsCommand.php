<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\AutoReplyService;
use App\Services\ContactSyncService;
use App\Services\DynalinkService;
use App\Services\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Nightly contacts sync (also run on demand): `php artisan contacts:sync`.
 *
 * Scheduled in routes/console.php at 03:10 local. Each tenant syncs with the
 * best token available (stored login → tenant token → service credential);
 * a tenant with none of the three is skipped, never fatal.
 */
class SyncContactsCommand extends Command
{
    protected $signature = 'contacts:sync {--domain= : Only sync this domain} {--user= : Only sync this Dynalink user}';
    protected $description = 'Sync the local contacts cache with the Dynalink address book (portal wins).';

    public function handle(ContactSyncService $sync): int
    {
        $pairs = [];
        if ($this->option('domain') && $this->option('user')) {
            $pairs[] = [(string) $this->option('domain'), (string) $this->option('user')];
        } else {
            foreach (Tenant::query()->get() as $t) {
                if (!$t->domain || !$t->dynalink_user) continue;
                if (method_exists($t, 'isActive') && !$t->isActive()) continue;
                $pairs[] = [$t->domain, $t->dynalink_user];
            }
        }

        if ($pairs === []) {
            $this->info('No tenants to sync.');
            return self::SUCCESS;
        }

        $ok = 0; $skipped = 0; $failed = 0;
        foreach ($pairs as [$domain, $user]) {
            $token = null;
            try {
                $token = $this->resolveToken($domain, $user);
            } catch (\Throwable $e) {
                Log::warning("contacts:sync token resolution failed for {$user}@{$domain}: " . $e->getMessage());
            }
            if (!$token) {
                $skipped++;
                Log::info("contacts:sync skipped {$user}@{$domain} — no usable provider token (stored login, tenant, or service credential).");
                $this->warn("skip {$domain}/{$user}: no token");
                continue;
            }
            try {
                $res = $sync->sync($token, $domain, $user);
                $ok++;
                Cache::put("contacts:last_sync:{$domain}:{$user}", [
                    'at'      => now()->toISOString(),
                    'created' => $res['created'], 'updated' => $res['updated'],
                    'removed' => $res['removed'], 'pushed'  => $res['pushed'],
                ], now()->addDays(30));
                $this->info(sprintf(
                    '%s/%s: +%d created, %d updated, %d pushed, -%d removed (total %d)',
                    $domain, $user, $res['created'], $res['updated'], $res['pushed'], $res['removed'], $res['count']
                ));
            } catch (\Throwable $e) {
                $failed++;
                Log::warning("contacts:sync failed for {$user}@{$domain}: " . $e->getMessage());
                $this->error("fail {$domain}/{$user}: " . $e->getMessage());
            }
        }

        $this->info("contacts:sync done — {$ok} synced, {$skipped} skipped, {$failed} failed.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Best available provider token for a nightly (unattended) sync.
     *
     * 1. Stored refresh token from a portal login (what the UI resync uses).
     * 2. Tenant access token — portal-only tenants never log in through
     *    this app, so without this step their nightly sync silently
     *    skipped forever and the "Synced" date froze.
     * 3. Dynalink service credential (Super → Settings or .env), minted
     *    and cached exactly like ResolvesActor does for agents.
     */
    protected function resolveToken(string $domain, string $user): ?string
    {
        try {
            $t = app(AutoReplyService::class)->userToken($domain, $user);
            if ($t) return $t;
        } catch (\Throwable $e) {
            // fall through to the tenant token
        }

        $tenant = Tenant::where('domain', $domain)->where('dynalink_user', $user)->first()
            ?? Tenant::where('domain', $domain)->first();
        if ($tenant && (!method_exists($tenant, 'isActive') || $tenant->isActive())) {
            try {
                $t = $tenant->accessToken();
                if ($t) return $t;
            } catch (\Throwable $e) {
                Log::warning("contacts:sync tenant token failed for {$domain}: " . $e->getMessage());
            }
        }

        $su = Settings::dynalinkServiceCredential('user');
        $sp = Settings::dynalinkServiceCredential('pass');
        if ($su && $sp) {
            return Cache::remember(
                "dynalink:service_token:{$domain}:{$user}", 3000,
                fn() => app(DynalinkService::class)->login($su, $sp)['access_token']
            );
        }

        return null;
    }
}

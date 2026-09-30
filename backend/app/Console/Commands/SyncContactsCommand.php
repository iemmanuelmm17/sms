<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\AutoReplyService;
use App\Services\ContactSyncService;
use App\Services\DynalinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Nightly contacts sync (also run on demand): `php artisan contacts:sync`.
 *
 * Scheduled in routes/console.php at 03:10 local. Each tenant syncs with its
 * own credential; a tenant without a usable token is skipped, never fatal.
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
                $token = app(AutoReplyService::class)->userToken($domain, $user);
            } catch (\Throwable $e) {
                // fall through
            }
            if (!$token) {
                $skipped++;
                Log::info("contacts:sync skipped {$user}@{$domain} — no stored provider token (log in once to enable).");
                $this->warn("skip {$domain}/{$user}: no token");
                continue;
            }
            try {
                $res = $sync->sync($token, $domain, $user);
                // The domain-level (shared) book rides along; its failure
                // degrades the run, never blocks the personal sync.
                $shared = null;
                try {
                    $shared = $sync->syncShared($token, $domain, $user);
                    if (empty($shared['provider_ok'])) {
                        $this->warn("  shared book: " . ($shared['errors'][0] ?? 'unreachable'));
                    }
                } catch (\Throwable $e) {
                    Log::warning("contacts:sync shared book failed for {$domain}: " . $e->getMessage());
                }
                $ok++;
                Cache::put("contacts:last_sync:{$domain}:{$user}", [
                    'at'      => now()->toISOString(),
                    'created' => $res['created'], 'updated' => $res['updated'],
                    'removed' => $res['removed'], 'pushed'  => $res['pushed'],
                    'shared'  => $shared['count'] ?? null,
                ], now()->addDays(30));
                $this->info(sprintf(
                    '%s/%s: +%d created, %d updated, %d pushed, -%d removed (total %d, shared %s)',
                    $domain, $user, $res['created'], $res['updated'], $res['pushed'], $res['removed'], $res['count'],
                    $shared === null ? 'n/a' : (string) $shared['count']
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
}

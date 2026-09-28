<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\SuperAdminAllowedIp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * php artisan superadmin:ip — manage the portal allowlist until the
 * Phase 3 web UI ships. Localhost is always allowed and needs no entry.
 */
class SuperAdminIpCommand extends Command
{
    protected $signature = 'superadmin:ip {--list} {--add=} {--label=} {--remove=}';
    protected $description = 'List, add, or remove superadmin portal allowed IPs';

    public function handle(): int
    {
        if ($add = $this->option('add')) {
            $cidr = trim((string) $add);
            if (!$this->valid($cidr)) {
                $this->error('Use an IP (v4/v6) or an IPv4 CIDR like 192.168.1.0/24.');
                return 1;
            }
            SuperAdminAllowedIp::firstOrCreate(['cidr' => $cidr],
                ['label' => $this->option('label') ?: null]);
            Cache::forget('superadmin:ips');
            AuditLog::record(null, 'console', null, 'artisan superadmin:ip',
                'superadmin.ip.added', ['cidr' => $cidr], '127.0.0.1');
            $this->info("Allowed: {$cidr}");
            return 0;
        }
        if ($remove = $this->option('remove')) {
            $n = SuperAdminAllowedIp::where('cidr', trim((string) $remove))->delete();
            Cache::forget('superadmin:ips');
            AuditLog::record(null, 'console', null, 'artisan superadmin:ip',
                'superadmin.ip.removed', ['cidr' => trim((string) $remove)], '127.0.0.1');
            $this->info($n ? 'Removed.' : 'No such entry.');
            return 0;
        }
        $rows = SuperAdminAllowedIp::orderBy('cidr')->get(['cidr', 'label']);
        $this->info('Localhost (127.0.0.1, ::1) is always allowed.');
        if ($rows->isEmpty()) {
            $this->line('Allowlist is empty — localhost only.');
            return 0;
        }
        $this->table(['CIDR', 'Label'], $rows->map(fn($r) => [$r->cidr, $r->label ?? '—'])->all());
        return 0;
    }

    protected function valid(string $cidr): bool
    {
        return \App\Http\Middleware\EnsureSuperAdminIp::valid($cidr);
    }
}

<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * php artisan tenant:admin-reset {tenant} {username} — force-set a tenant
 * admin's password from the console (used until the portal ships).
 * All of the admin's sessions drop immediately.
 */
class TenantAdminResetCommand extends Command
{
    protected $signature = 'tenant:admin-reset {tenant} {username}';
    protected $description = "Force-reset a tenant admin's password (drops their sessions)";

    public function handle(): int
    {
        $tenant = Tenant::where('name', mb_strtolower(trim((string) $this->argument('tenant'))))->first();
        if (!$tenant) {
            $this->error('Tenant not found.');
            return 1;
        }
        $admin = TenantAdmin::where('tenant_id', $tenant->id)
            ->where('username', mb_strtolower(trim((string) $this->argument('username'))))->first();
        if (!$admin) {
            $this->error('Admin not found on that tenant.');
            return 1;
        }
        $pw = (string) $this->secret('New password (min 8 chars)');
        if (strlen($pw) < 8) {
            $this->error('Password needs at least 8 characters.');
            return 1;
        }
        if ($pw !== (string) $this->secret('Confirm password')) {
            $this->error('Passwords do not match.');
            return 1;
        }
        $admin->update([
            'password_hash' => Hash::make($pw),
            'session_version' => $admin->session_version + 1,
        ]);
        AuditLog::record($tenant->domain, 'console', null, 'artisan tenant:admin-reset',
            'tenant.admin.password-forced', ['tenant' => $tenant->name, 'admin' => $admin->username], '127.0.0.1');
        $this->info('Password updated — their sessions were dropped.');
        return 0;
    }
}

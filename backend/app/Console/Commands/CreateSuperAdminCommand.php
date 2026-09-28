<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\SuperAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * php artisan superadmin:create — create a superadmin account.
 * The password must be changed on first portal login.
 */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'superadmin:create';
    protected $description = 'Create a superadmin account';

    public function handle(): int
    {
        while (true) {
            $username = mb_strtolower(trim((string) $this->ask('Username (letters/numbers)')));
            if (!preg_match('/^[a-z0-9]+$/', $username)) {
                $this->error('Letters and numbers only.');
                continue;
            }
            if (SuperAdmin::where('username', $username)->exists()) {
                $this->error('That username is taken.');
                continue;
            }
            break;
        }
        $first = trim((string) $this->ask('First name (optional)')) ?: null;
        $last = trim((string) $this->ask('Last name (optional)')) ?: null;
        $pw = (string) $this->secret('Password (min 8 chars)');
        if (strlen($pw) < 8) {
            $this->error('Password needs at least 8 characters.');
            return 1;
        }
        if ($pw !== (string) $this->secret('Confirm password')) {
            $this->error('Passwords do not match.');
            return 1;
        }
        SuperAdmin::create([
            'username' => $username, 'first_name' => $first, 'last_name' => $last,
            'password_hash' => Hash::make($pw), 'status' => 'active',
            'must_change_password' => true,
        ]);
        AuditLog::record(null, 'console', null, 'artisan superadmin:create',
            'superadmin.created', ['username' => $username], '127.0.0.1');
        $this->info("Superadmin '{$username}' created — the password must be changed on first login.");
        return 0;
    }
}

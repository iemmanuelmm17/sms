<?php

namespace App\Console\Commands;

use App\Http\Controllers\AgentController;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantAdmin;
use App\Services\DynalinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * php artisan tenant:create — interactive tenant setup (used until the
 * superadmin portal ships in Phase 2). Collects everything first, then
 * creates the tenant + first admin together so a typo never orphans data.
 */
class CreateTenantCommand extends Command
{
    protected $signature = 'tenant:create';
    protected $description = "Create a tenant with its Dynalink credential and first admin";

    public function handle(): int
    {
        $name = $this->askTenantName();
        $domain = $this->askRequired('Dynalink domain (e.g. 1234.ExampleCo)');
        $dlUser = $this->askRequired('Dynalink username');
        if (Tenant::where('domain', $domain)->where('dynalink_user', $dlUser)->exists()) {
            $this->error('A tenant already uses that Dynalink identity.');
            return 1;
        }
        $dlPass = $this->secret('Dynalink password');
        if ($dlPass === null || $dlPass === '') {
            $this->error('Dynalink password is required.');
            return 1;
        }
        $company = $this->ask('Company name (optional)') ?: null;

        $this->line('--- First tenant admin ---');
        $username = $this->askAdminName();
        $first = $this->askRequired('First name');
        $last = $this->askRequired('Last name');
        $pw = (string) $this->secret('Admin password (min 8 chars)');
        if (strlen($pw) < 8) {
            $this->error('Password needs at least 8 characters.');
            return 1;
        }
        if ($pw !== (string) $this->secret('Confirm password')) {
            $this->error('Passwords do not match.');
            return 1;
        }
        $question = $this->askRequired('Secret question');
        $answer = (string) $this->secret('Secret answer');
        if (trim($answer) === '') {
            $this->error('Secret answer is required.');
            return 1;
        }

        try {
            app(DynalinkService::class)->login($dlUser . '@' . $domain, $dlPass);
            $this->info('Dynalink credential verified.');
        } catch (\Throwable $e) {
            $this->warn('Could not verify the Dynalink credential (creating anyway).');
        }

        $tenant = Tenant::create([
            'name' => $name, 'domain' => $domain, 'dynalink_user' => $dlUser,
            'dynalink_pass' => $dlPass, 'company_name' => $company, 'status' => 'active',
        ]);
        TenantAdmin::create([
            'tenant_id' => $tenant->id, 'username' => $username,
            'first_name' => $first, 'last_name' => $last,
            'password_hash' => Hash::make($pw),
            'secret_question' => trim($question),
            'secret_answer_hash' => Hash::make(AgentController::normalizeAnswer($answer)),
            'status' => 'active',
        ]);
        AuditLog::record($tenant->domain, 'console', null, 'artisan tenant:create',
            'tenant.created', ['tenant' => $name, 'admin' => $username], '127.0.0.1');
        $this->info("Tenant '{$name}' created. Admin login: {$username}@{$name}");
        $this->line('No main SMS number was set — assign it in the superadmin portal (tenant detail).');
        return 0;
    }

    protected function askTenantName(): string
    {
        while (true) {
            $v = mb_strtolower(trim((string) $this->ask('Tenant name (login suffix, e.g. acme)')));
            if (!preg_match('/^[a-z0-9-]{2,60}$/', $v)) {
                $this->error('Use 2-60 chars: a-z, 0-9, hyphen.');
                continue;
            }
            if (Tenant::where('name', $v)->exists()) {
                $this->error('That tenant name is taken.');
                continue;
            }
            return $v;
        }
    }

    protected function askAdminName(): string
    {
        while (true) {
            $v = mb_strtolower(trim((string) $this->ask('Admin username (letters/numbers, e.g. sam)')));
            if (!preg_match('/^[a-z0-9]+$/', $v)) {
                $this->error('Letters and numbers only.');
                continue;
            }
            return $v;
        }
    }

    protected function askRequired(string $q): string
    {
        while (true) {
            $v = trim((string) $this->ask($q));
            if ($v !== '') return $v;
            $this->error('This field is required.');
        }
    }
}

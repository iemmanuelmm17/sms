<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Contact;
use Illuminate\Console\Command;

/**
 * php artisan privacy:anonymize — GDPR-style erasure, TCPA-aware.
 *
 * Blanks the identifying fields of local contact rows matching a phone
 * number (names, company, email, every phone column, raw provider JSON).
 *
 * Deliberate tradeoff, documented on purpose:
 *   - The contact ROW stays (referential integrity for conversation meta).
 *   - OptEvent history is NEVER touched — the opt-out record IS the legal
 *     proof that the number must not be texted again; erasing it would
 *     create TCPA exposure worse than the privacy request it answers.
 *   - Message bodies are not local (Dynalink portal); handle them there.
 */
class PrivacyAnonymizeCommand extends Command
{
    protected $signature = 'privacy:anonymize
                            {--domain= : Tenant domain (required)}
                            {--phone= : Phone number of the data subject (required)}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Anonymize local contact PII for one phone number (keeps opt-out history for TCPA compliance)';

    public function handle(): int
    {
        $domain = trim((string) $this->option('domain'));
        $digits = preg_replace('/\D', '', (string) $this->option('phone'));
        if ($domain === '' || $digits === '' || $digits === null) {
            $this->error('--domain and --phone are both required.');
            return self::FAILURE;
        }

        $matches = Contact::where('domain', $domain)->get()->filter(function ($c) use ($digits) {
            foreach (['phone_work', 'phone_cell', 'phone_home', 'phone_fax'] as $f) {
                $v = preg_replace('/\D', '', (string) $c->{$f});
                if ($v !== '' && ($v === $digits || str_ends_with($v, $digits) || str_ends_with($digits, $v))) {
                    return true;
                }
            }
            return false;
        })->values();

        if ($matches->isEmpty()) {
            $this->warn('No local contact rows match that domain+phone.');
            $this->line('(Their data may exist only in the Dynalink portal — nothing local to erase.)');
            return self::FAILURE;
        }

        $this->line($matches->count() . ' contact row(s) will be anonymized:');
        foreach ($matches as $c) {
            $this->line('  · #' . $c->id . ' ' . trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) . ' <' . ($c->email ?? '') . '>');
        }
        $this->line('Opt-out history is kept (TCPA legal record). This cannot be undone.');
        if (!$this->option('force') && !$this->confirm('Proceed?')) {
            $this->line('Cancelled.');
            return self::SUCCESS;
        }

        foreach ($matches as $c) {
            $c->first_name = null;
            $c->middle_name = null;
            $c->last_name = null;
            $c->email = null;
            $c->company = null;
            $c->phone_work = null;
            $c->phone_cell = null;
            $c->phone_home = null;
            $c->phone_fax = null;
            // `raw` has an array cast — assign the array, not a JSON string.
            $c->raw = ['anonymized' => true, 'at' => now()->toIso8601String()];
            $c->save();
        }

        AuditLog::record($domain, 'console', null, 'cli', 'privacy.anonymized', [
            'rows' => $matches->count(),
            'phone_hash' => substr(hash('sha256', $digits), 0, 12),
        ], '127.0.0.1');

        $this->info('Anonymized ' . $matches->count() . ' contact row(s). Opt-out history preserved.');
        return self::SUCCESS;
    }
}

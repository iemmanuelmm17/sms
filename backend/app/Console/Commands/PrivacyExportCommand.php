<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\OptEvent;
use Illuminate\Console\Command;

/**
 * php artisan privacy:export — GDPR/CCPA-style data subject access request.
 *
 * Exports everything this system stores locally about a tenant domain
 * (optionally narrowed to one phone number) as JSON:
 *   - contacts   (names, phones, email, company, raw provider record)
 *   - opt events (consent/opt-out history — legally important to KEEP)
 *
 * Message bodies and session threads are NOT stored locally — they live in
 * the Dynalink portal; export them there if a request covers them.
 */
class PrivacyExportCommand extends Command
{
    protected $signature = 'privacy:export
                            {--domain= : Tenant domain to export (required)}
                            {--phone= : Narrow to one phone number (digits matched loosely)}
                            {--out= : Output file (default: storage/app/privacy/<domain>_<timestamp>.json)}';

    protected $description = 'Export locally stored PII (contacts + opt-out history) for a data subject request';

    public function handle(): int
    {
        $domain = trim((string) $this->option('domain'));
        if ($domain === '') {
            $this->error('--domain is required (the tenant domain, e.g. from Super → Tenants).');
            return self::FAILURE;
        }
        $digits = preg_replace('/\D', '', (string) $this->option('phone'));

        $contacts = Contact::where('domain', $domain)->get();
        $opts = OptEvent::where('domain', $domain)->get();

        if ($digits !== '' && $digits !== null) {
            $contacts = $contacts->filter(function ($c) use ($digits) {
                foreach (['phone_work', 'phone_cell', 'phone_home', 'phone_fax'] as $f) {
                    if (str_contains(preg_replace('/\D', '', (string) $c->{$f}), $digits)) {
                        return true;
                    }
                }
                return str_contains(preg_replace('/\D', '', json_encode($c->raw ?? [])), $digits);
            })->values();
            $opts = $opts->filter(fn ($o) => str_contains(preg_replace('/\D', '', (string) $o->phone_number), $digits))->values();
        }

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'domain' => $domain,
            'phone_filter' => $digits ?: null,
            'note' => 'Message bodies live in the Dynalink portal, not in this database.',
            'contacts' => $contacts,
            'opt_events' => $opts,
        ];

        $out = (string) $this->option('out');
        if ($out === '') {
            $dir = storage_path('app/privacy');
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $domain);
            $out = $dir . DIRECTORY_SEPARATOR . $safe . '_' . date('Y-m-d_His') . '.json';
        }

        if (file_put_contents($out, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            $this->error("Could not write {$out}");
            return self::FAILURE;
        }

        $this->info("Exported {$contacts->count()} contact(s), {$opts->count()} opt-event(s) → {$out}");
        $this->line('Treat this file as sensitive: delete it once the request is fulfilled.');
        return self::SUCCESS;
    }
}

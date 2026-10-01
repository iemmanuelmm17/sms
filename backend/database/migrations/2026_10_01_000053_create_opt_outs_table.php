<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * TCPA do-not-contact list moves from storage/app/optouts/{domain}.json into
 * the database: compliance state should be transactional, backed up with the
 * DB and auditable — not a loose JSON file.
 *
 * Existing JSON files are imported here (idempotently — insertOrIgnore never
 * clobbers newer DB rows) and stay on disk as a cold backup; OptOutService
 * keeps mirroring every write to the JSON file as well.
 *
 * The per-EVENT audit trail already lives in opt_events (OptEvent model —
 * every opt-out/opt-in with keyword + timestamp). This table holds the
 * CURRENT state: who is blocked, and on which sending numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('opt_outs')) {
            Schema::create('opt_outs', function (Blueprint $t) {
                $t->id();
                $t->string('domain', 190)->index();
                $t->string('digits', 15);                 // the blocked contact, as stored (10 or 11 digit variants coexist like the JSON keys did)
                $t->string('source', 32)->default('manual'); // stop-keyword | manual
                $t->text('note')->nullable();
                $t->text('numbers');                      // JSON: ["*"] = all senders, else [digits...] of business numbers
                $t->timestamps();
                $t->unique(['domain', 'digits']);
            });
        }
        $this->importJson();
    }

    public function down(): void
    {
        // The JSON mirror files remain on disk, so no compliance data is lost.
        Schema::dropIfExists('opt_outs');
    }

    /** Seed from storage/app/optouts/*.json — tenant domains first, then any stray file. */
    protected function importJson(): void
    {
        try {
            $store = Storage::disk('local');
            if (!$store->exists('optouts')) return;

            // Map real tenant domains to their sanitized file name; files that
            // match no tenant fall back to the file name as the domain key.
            $files = [];
            try {
                foreach (\App\Models\Tenant::pluck('domain') as $dom) {
                    $rel = 'optouts/' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $dom) . '.json';
                    if ($store->exists($rel)) $files[$rel] = (string) $dom;
                }
            } catch (\Throwable $e) {
                // tenants table missing — fall through to the file-name pass
            }
            foreach ((array) $store->files('optouts') as $rel) {
                if (!isset($files[$rel])) $files[$rel] = preg_replace('/^optouts\/|\.json$/', '', (string) $rel);
            }

            $rows = [];
            $now = now();
            foreach ($files as $rel => $domain) {
                $list = json_decode((string) $store->get($rel), true);
                if (!is_array($list)) continue;
                foreach ($list as $digits => $meta) {
                    $digits = preg_replace('/[^0-9]/', '', (string) $digits);
                    if ($digits === '' || $domain === '') continue;
                    $meta = is_array($meta) ? $meta : [];
                    $at = null;
                    try {
                        $at = !empty($meta['at']) ? \Illuminate\Support\Carbon::parse($meta['at']) : null;
                    } catch (\Throwable $e) {
                        $at = null;
                    }
                    $nums = array_values(array_map('strval', (array) ($meta['numbers'] ?? ['*'])));
                    $rows[] = [
                        'domain' => mb_substr((string) $domain, 0, 190),
                        'digits' => mb_substr($digits, 0, 15),
                        'source' => mb_substr((string) ($meta['source'] ?? 'manual'), 0, 32),
                        'note' => isset($meta['note']) ? mb_substr((string) $meta['note'], 0, 65000) : null,
                        'numbers' => json_encode($nums === [] ? ['*'] : $nums),
                        'created_at' => $at ?? $now,
                        'updated_at' => $at ?? $now,
                    ];
                }
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('opt_outs')->insertOrIgnore($chunk);
            }
        } catch (\Throwable $e) {
            // Never fail the migration over the seed step — the service keeps
            // reading the JSON files until rows exist. Log loudly instead.
            \Illuminate\Support\Facades\Log::error('optouts:migration-import-failed', ['error' => (string) $e]);
        }
    }
};

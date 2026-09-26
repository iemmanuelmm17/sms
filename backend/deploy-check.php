<?php
/**
 * Deployment self-check.
 *
 *   cd C:\xampp\sms_app\backend
 *   php deploy-check.php
 *
 * Answers one question: is the code running on THIS machine the code the
 * fixes were written against? Every item below maps to a specific reported
 * bug, so a FAIL tells you exactly which fix has not landed.
 *
 * Read-only. Safe to run on production.
 */

$root = __DIR__;
$pass = 0; $fail = 0; $warn = 0;

/** Bumped whenever the bundle changes, so a stale copy is self-evident. */
const BUILD_STAMP = '2026-09-25a';

function line(string $s = ''): void { echo $s, PHP_EOL; }
function ok(string $label): void { global $pass; $pass++; line("  [ OK ]  {$label}"); }
function bad(string $label, string $fix): void {
    global $fail; $fail++; line("  [FAIL]  {$label}"); line("          -> {$fix}");
}
function warn(string $label, string $note): void {
    global $warn; $warn++; line("  [WARN]  {$label}"); line("          -> {$note}");
}

/** Does $file contain $needle? */
function has(string $file, string $needle): bool {
    global $root;
    $p = $root . '/' . $file;
    if (!is_file($p)) return false;
    return str_contains((string) file_get_contents($p), $needle);
}

line();
line('Bundle build: ' . BUILD_STAMP);
line('If that does not match the bundle you extracted, you are running an');
line('older copy — re-extract sms-app-fixes.tar.gz before trusting this report.');
line();
line('=== 1. Backend source ===');

$srcChecks = [
    ['Auto-reply crash fix (AgentIdentity::assignedNumbers)',
     'app/Http/Controllers/AutoReplyController.php',
     'allowed list must travel with the',
     'Deploy the latest app/Http/Controllers/AutoReplyController.php'],

    ['Reporting tenant fix (portal sends were logged with no tenant)',
     'app/Models/SentMessageLog.php', 'tenantIdForDomain',
     'Deploy the latest app/Models/SentMessageLog.php'],

    ['from_number normalised on write',
     'app/Http/Controllers/MessageSessionController.php', 'Store digits only',
     'Deploy the latest app/Http/Controllers/MessageSessionController.php'],

    ['TCPA bulk footer resolver',
     'app/Services/CompanySettingsService.php', 'function tcpaFooter',
     'Deploy the latest app/Services/CompanySettingsService.php'],

    ['Scheduler uses the footer resolver',
     'app/Jobs/SendScheduledMessage.php', 'tcpaFooter(',
     'Deploy the latest app/Jobs/SendScheduledMessage.php'],

    ['Unlock endpoint',
     'app/Http/Controllers/AgentIdentityController.php', 'function unlock',
     'Deploy the latest app/Http/Controllers/AgentIdentityController.php'],

    ['Read vs send split',
     'app/Services/AgentAccess.php', 'function readableNumbers',
     'Deploy the latest app/Services/AgentAccess.php'],

    ['Assignment accepts identity_id',
     'app/Http/Controllers/ConversationMetaController.php', 'identity_id',
     'Deploy the latest app/Http/Controllers/ConversationMetaController.php'],

    ['New messages post as the number\'s owning extension',
     'app/Http/Controllers/MessageController.php', 'function senderFor',
     'Deploy the latest app/Http/Controllers/MessageController.php'],

    ['Report query is portable (no nested REPLACE)',
     'app/Http/Controllers/ReportController.php', 'Plain, indexed whereIn',
     'Deploy the latest app/Http/Controllers/ReportController.php'],

    ['API server pool (rotation)',
     'app/Services/ApiServerPool.php', 'class ApiServerPool',
     'Copy app/Services/ApiServerPool.php, then composer dump-autoload'],

    ['Dynalink rotates across the pool',
     'app/Services/DynalinkService.php', 'ApiServerPool::next',
     'Deploy the latest app/Services/DynalinkService.php'],

    ['Conversation meta is tenant-wide (shared queue)',
     'app/Http/Controllers/ConversationMetaController.php', 'TENANT-WIDE',
     'Deploy the latest app/Http/Controllers/ConversationMetaController.php'],

    ['Queue spans every number',
     'app/Http/Controllers/MessageSessionController.php', 'queuedSessions',
     'Deploy the latest app/Http/Controllers/MessageSessionController.php'],
];

foreach ($srcChecks as [$label, $file, $needle, $fix]) {
    has($file, $needle) ? ok($label) : bad($label, $fix);
}

line();
line('=== 1b. File freshness (SHA256 vs shipped manifest) ===');
$manifest = dirname($root) . '/FIXES-MANIFEST.txt';
if (!is_file($manifest)) {
    warn('FIXES-MANIFEST.txt not found next to the backend folder',
         'Copy it from the bundle to verify file-by-file.');
} else {
    foreach (file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        if (str_starts_with(trim($ln), '#')) continue;
        [$want, $rel] = array_values(array_filter(preg_split('/\s+/', trim($ln))));
        // Manifest paths are repo-relative (backend/... or frontend/...).
        $abs = dirname($root) . '/' . $rel;
        if (!is_file($abs)) { bad("missing: {$rel}", 'Extract it from sms-app-fixes.tar.gz'); continue; }
        $got = substr(hash_file('sha256', $abs), 0, 16);
        $got === $want
            ? ok($rel)
            : bad("STALE: {$rel}", "yours {$got} / expected {$want} — overwrite from the bundle");
    }
}

line();
line('=== 1c. Frontend source ===');
$fe = dirname($root) . '/frontend/src';
if (!is_dir($fe)) {
    warn('frontend/src not found next to backend/',
         'Run this from the project root so the frontend can be checked too.');
} else {
    $feChecks = [
        ['Cross-number reply starts a new conversation', 'pages/Messages.jsx', 'crossNumber'],
        ['MMS renders inline (not a link)', 'pages/Messages.jsx', 'Click to enlarge'],
        ['MMS decodes immediately', 'pages/Messages.jsx', 'loading="eager"'],
        ['Conversation list windowed at 30', 'pages/Messages.jsx', 'const PAGE = 30'],
        ['"Claimed" filter tab', 'pages/Messages.jsx', "'claimed', 'Claimed'"],
        ['Short signature helper', 'lib/signature.js', 'function shortName'],
        ['Contact quick-add creates the company', 'components/QuickAddContact.jsx', 'createCompany'],
        ['TCPA bulk footer UI', 'pages/TCPA.jsx', 'Bulk send footer'],
        ['Unlock button on Users', 'pages/Users.jsx', 'Unlock'],
        ['Reference data cached across navigation', 'context/ReferenceDataContext.jsx', 'ReferenceDataProvider'],
        ['Messages reads the cache (no refetch on return)', 'pages/Messages.jsx', 'useReferenceData'],
        ['Session list paints from cache', 'pages/Messages.jsx', 'sessionCache'],
        ['API servers UI (superadmin)', 'pages/super/SuperSettings.jsx', 'API servers'],
        ['Claim keeps a thread queued', 'pages/Messages.jsx', 'deliberately leaves the thread IN the'],
        ['Remove from Queue action', 'pages/Messages.jsx', 'removeFromQueue'],
    ];
    foreach ($feChecks as [$label, $rel, $needle]) {
        $p = $fe . '/' . $rel;
        if (!is_file($p)) { bad("{$label} — {$rel} missing", 'Extract frontend/src from the bundle'); continue; }
        str_contains((string) file_get_contents($p), $needle)
            ? ok($label)
            : bad($label, "Deploy the latest frontend/src/{$rel}");
    }
    // Renamed file: leaving it behind keeps the old page alive.
    is_file($fe . '/pages/People.jsx')
        ? bad('stale pages/People.jsx present', 'DELETE it — renamed to Users.jsx')
        : ok('stale pages/People.jsx removed');
}

line();
line('=== 2. New files present ===');
foreach ([
    'app/Models/AgentIdentity.php',
    'app/Models/AgentNumberGrant.php',
    'app/Services/AgentAccess.php',
    'app/Http/Controllers/AgentIdentityController.php',
] as $f) {
    is_file($root . '/' . $f)
        ? ok($f)
        : bad($f . ' missing', 'Copy the file, then run: composer dump-autoload');
}

line();
line('=== 3. Migrations on disk ===');
foreach ([
    '2026_09_23_000045_create_agent_identities_and_grants.php' => 'agent_identities + grants',
    '2026_09_23_000046_add_portal_profile_to_agent_identities.php' => 'portal name/email columns',
    '2026_09_24_000047_add_identity_id_to_conversation_meta.php' => 'conversation assignment',
    '2026_09_24_000048_normalize_sent_message_log_rows.php' => 'report row repair',
    '2026_09_25_000049_share_conversation_meta_tenant_wide.php' => 'shared conversation metadata',
] as $f => $what) {
    is_file($root . '/database/migrations/' . $f)
        ? ok("{$what} ({$f})")
        : bad("{$what} migration missing", "Copy database/migrations/{$f}");
}

line();
line('=== 4. Database ===');

$booted = false;
try {
    // Guard first: a bare require emits a raw PHP warning before we can catch it.
    if (!is_file($root . '/vendor/autoload.php')) {
        throw new RuntimeException('vendor/autoload.php not found — run composer install');
    }
    require $root . '/vendor/autoload.php';
    $app = require_once $root . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $booted = true;
} catch (Throwable $e) {
    warn('Could not boot Laravel: ' . $e->getMessage(),
         'Run the DB checks manually, or run this from the project root.');
}

if ($booted) {
    try {
        $sm = Illuminate\Support\Facades\Schema::class;
        $need = [
            'agent_identities' => ['ext', 'first_name', 'profile_synced_at'],
            'agent_number_grants' => ['ext', 'number'],
            'conversation_meta' => ['identity_id'],
        ];
        foreach ($need as $table => $cols) {
            if (!$sm::hasTable($table)) {
                bad("table `{$table}` missing", 'php artisan migrate');
                continue;
            }
            foreach ($cols as $c) {
                $sm::hasColumn($table, $c)
                    ? ok("{$table}.{$c}")
                    : bad("{$table}.{$c} missing", 'php artisan migrate');
            }
        }

        // Reporting: sends logged with no tenant are invisible to every report.
        if ($sm::hasTable('sent_message_logs')) {
            $orphans = Illuminate\Support\Facades\DB::table('sent_message_logs')
                ->whereNull('tenant_id')->count();
            $total = Illuminate\Support\Facades\DB::table('sent_message_logs')->count();
            if ($total === 0) {
                warn('sent_message_logs is empty',
                     'Reporting will be blank until messages are sent.');
            } elseif ($orphans > 0) {
                warn("{$orphans} of {$total} logged sends have tenant_id = NULL",
                     'Run: php artisan migrate   (migration 000048 repairs these)');
            } else {
                ok("all {$total} logged sends have a tenant");
            }
        }
        if ($sm::hasTable('sent_message_logs')) {
            $formatted = Illuminate\Support\Facades\DB::table('sent_message_logs')
                ->whereNotNull('from_number')
                ->where('from_number', 'like', '%+%')
                ->orWhere('from_number', 'like', '%(%')
                ->orWhere('from_number', 'like', '%-%')
                ->count();
            $formatted > 0
                ? warn("{$formatted} rows still have a formatted from_number",
                       'Run: php artisan migrate   (migration 000048 normalises these)')
                : ok('from_number values are normalised to digits');
        }
    } catch (Throwable $e) {
        warn('DB check failed: ' . $e->getMessage(), 'Check your .env database settings.');
    }
}

line();
line('=== 5. Caches ===');
foreach ([
    'bootstrap/cache/config.php' => ['config is cached', 'php artisan config:clear'],
    'bootstrap/cache/routes-v7.php' => ['routes are cached', 'php artisan route:clear'],
] as $f => [$what, $cmd]) {
    if (is_file($root . '/' . $f)) {
        warn($what, "New routes/settings will NOT apply until: {$cmd}");
    } else {
        ok(str_replace(' are ', ' not ', str_replace(' is ', ' not ', $what)));
    }
}

line();
line(str_repeat('=', 58));
line("  {$pass} passed, {$fail} failed, {$warn} warning(s)");
line(str_repeat('=', 58));

if ($fail > 0) {
    line();
    line('Some fixes are NOT on this machine. Deploy the files listed above,');
    line('then run:');
    line('    composer dump-autoload');
    line('    php artisan migrate');
    line('    php artisan config:clear && php artisan route:clear && php artisan cache:clear');
    line('    (restart Apache so PHP opcache drops the old files)');
}

line();
line('Pre-fix report rows are repaired by migration 000048 (portable, no raw SQL).');
line('If any remain after `php artisan migrate`, re-run it — it is idempotent.');
line();

exit($fail > 0 ? 1 : 0);

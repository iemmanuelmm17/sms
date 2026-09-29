<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * php artisan backup:run — one timestamped folder with everything that
 * cannot be recreated from git:
 *
 *   database.sqlite   via VACUUM INTO (crash-consistent snapshot, safe
 *                     while serve/queue/reverb keep writing — a plain file
 *                     copy can capture half-written pages + a stale -wal)
 *   env.bak           the .env (APP_KEY — without it, encrypted tenant
 *                     passwords in a restored DB are unrecoverable)
 *   storage-app/      company settings JSONs, uploaded logos, opt-out files
 *
 * Scheduled nightly (routes/console.php, 02:30). Default target is
 * storage/app/backups — set BACKUP_DIR in .env (or --dir) to a DIFFERENT
 * disk for real disaster coverage. Retention: newest --keep folders.
 *
 * Restore: stop PHP (taskkill /F /IM php.exe), copy database.sqlite back
 * over backend/database/, env.bak back to backend/.env, storage-app over
 * backend/storage/app, then start-all + php artisan queue:restart.
 */
class BackupCommand extends Command
{
    protected $signature = 'backup:run
                            {--dir= : Target directory (default: BACKUP_DIR from .env, else storage/app/backups)}
                            {--keep=14 : Number of dated backups to retain}';

    protected $description = 'Back up database.sqlite + .env + storage/app into a timestamped folder (nightly via the scheduler)';

    public function handle(): int
    {
        $dir = (string) ($this->option('dir') ?: env('BACKUP_DIR') ?: storage_path('app/backups'));
        $keep = max(1, (int) $this->option('keep'));
        $stamp = date('Y-m-d_His');
        $dest = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . $stamp;

        try {
            if (!is_dir($dest) && !mkdir($dest, 0775, true) && !is_dir($dest)) {
                throw new \RuntimeException("Cannot create backup dir: {$dest}");
            }

            // 1) SQLite snapshot.
            $db = (string) config('database.connections.sqlite.database');
            if ($db !== '' && $db !== ':memory:' && is_file($db)) {
                $pdo = DB::connection('sqlite')->getPdo();
                $target = str_replace("'", "''", $dest . DIRECTORY_SEPARATOR . 'database.sqlite');
                $pdo->exec("VACUUM INTO '{$target}'");
                $this->line('  ✓ database.sqlite snapshot');
            } else {
                $this->warn('  ! sqlite file not found — DB snapshot skipped (check DB_CONNECTION/database path)');
            }

            // 2) .env (APP_KEY!).
            if (is_file(base_path('.env'))) {
                copy(base_path('.env'), $dest . DIRECTORY_SEPARATOR . 'env.bak');
                $this->line('  ✓ .env → env.bak');
            }

            // 3) storage/app minus the backup destination itself (it usually
            //    lives inside storage/app — never recurse into it).
            $count = $this->copyTree(storage_path('app'), $dest . DIRECTORY_SEPARATOR . 'storage-app', $dest);
            $this->line("  ✓ storage/app ({$count} files)");

            // 4) Retention.
            // GLOB_ONLYDIR is silently ignored on Windows — filter explicitly.
            $folders = array_values(array_filter(
                glob(rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . '20*') ?: [],
                'is_dir'
            ));
            sort($folders);
            while (count($folders) > $keep) {
                $old = (string) array_shift($folders);
                $this->deleteTree($old);
                $this->line('  · pruned ' . basename($old));
            }

            Log::info('Backup completed', ['dir' => $dest]);
            $this->info("Backup OK → {$dest}");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('Backup FAILED: ' . $e->getMessage(), ['dir' => $dest]);
            $this->error('Backup FAILED: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    protected function copyTree(string $src, string $dst, string $excludeDir): int
    {
        if (!is_dir($src)) {
            return 0;
        }
        $n = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $path = $item->getPathname();
            if (str_starts_with($path, $excludeDir)) {
                continue; // never copy the backups folder into itself
            }
            $rel = ltrim(str_replace('\\', '/', substr($path, strlen(rtrim($src, '\\/')))), '/');
            $target = $dst . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0775, true);
                }
            } else {
                $tdir = dirname($target);
                if (!is_dir($tdir)) {
                    mkdir($tdir, 0775, true);
                }
                if (copy($path, $target)) {
                    $n++;
                }
            }
        }
        return $n;
    }

    protected function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

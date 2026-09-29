<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

// Email↔SMS gateway: poll the inbox every minute. Requires the
// scheduler to run (cron: `php artisan schedule:run` every minute).
Schedule::command('mail:poll-email-sms')->everyMinute()->withoutOverlapping(5);

// Contacts: nightly two-way sync with the portal (local table is the read
// source; this keeps it fresh when contacts are edited directly in Dynalink).
Schedule::command('contacts:sync')->dailyAt('03:10')->withoutOverlapping(30);

// Nightly local backup: consistent SQLite snapshot (VACUUM INTO) + .env +
// uploaded files, 14 generations kept. Point BACKUP_DIR at another disk in
// .env for real safety. Manual run: `php artisan backup:run`.
Schedule::command('backup:run')->dailyAt('02:30')->withoutOverlapping(60);

// Queue worker heartbeat: queue:work loops even when idle, so every loop
// iteration refreshes this timestamp. The Scheduler page reads it via
// GET /api/ops/health to show worker online/offline. (Registered here
// because console routes load in the worker's boot context.)
Queue::looping(function () {
    try {
        Cache::put('ops:queue-heartbeat', now()->toISOString(), now()->addMinutes(10));
    } catch (\Throwable $e) {
    }
});
